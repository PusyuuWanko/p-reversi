(() => {
  'use strict';

  // =====================================================================
  // P2P対局の「合図コード」を音波で送受信する層(quiet.js の薄いラッパー)。
  //
  // 送るのは32桁の16進文字列(=16バイト)ただ1つだけで、WebRTCのSDPそのものは
  // 一切通さない。SDPはbase64で2〜4KBあり、音響モデムの1フレーム(25〜34バイト)
  // に対して100フレーム以上になるうえ、quiet.jsはフレームが1つ欠けると
  // 組み立て中のデータを丸ごと捨てるため、現実的に成功しないため
  // (index.phpのシグナリング節のコメントも参照)。
  //
  // 音波に載せるのは MAGIC(2バイト) + コード(16バイト) + XORチェックサム(1バイト)
  // の計19バイト。quiet.js自身もフレームごとにCRCを持つが、こちらでも頭と
  // 尻を固めておくと、環境音をたまたま復号できてしまった場合にコードとして
  // 受け入れずに済む。
  //
  // 受信側から送信側への返事の経路が無いため、送信は「同じものを一定間隔で
  // 何度も繰り返す」方式にしてある。受信側はその繰り返しのどれか1回が
  // 通ればよく、1回きりの送信に比べて成功率が大きく上がる。
  // =====================================================================

  const QUIET_ASSET_PREFIX = './assets/vendor/quiet/';

  const CODE_BYTES = 16;
  const CODE_HEX_LENGTH = CODE_BYTES * 2;
  const MAGIC = [0x50, 0x52]; // 'PR'(PusyuuReversi)
  const PAYLOAD_BYTES = MAGIC.length + CODE_BYTES + 1;

  /** 同じコードを繰り返し流す回数と、その間隔(ms)。 */
  const SEND_ROUNDS = 8;
  const SEND_INTERVAL_MS = 900;
  /** 受信バッファの上限。これを超えた分は古い方から捨てて青天井に伸びないようにする。 */
  const MAX_ACCUM_BYTES = 256;

  // Quiet.init()は必ずここ(トップレベル、DOMContentLoaded待ちの外)で同期的に
  // 呼ぶ。quiet-emscripten.jsはasyncで読み込んでおり、DOMContentLoaded前に
  // フェッチが終わって実行され得るため、init()を遅らせるとquiet.js内部の
  // memoryInitializerPrefixURLが未設定のままonEmscriptenLocateFileが呼ばれ、
  // ReferenceErrorで初期化に失敗する(p-chatで確認済みの挙動)。
  if (window.Quiet) {
    Quiet.init({
      profilesPrefix: QUIET_ASSET_PREFIX,
      memoryInitializerPrefix: QUIET_ASSET_PREFIX,
    });
  }

  function normalizeCode(text) {
    const clean = String(text == null ? '' : text).replace(/[^0-9a-fA-F]/g, '').toLowerCase();
    return clean.length === CODE_HEX_LENGTH ? clean : null;
  }

  /** 表示・読み上げ用に4桁ずつ区切る。入力側は区切りを無視するので、どちらで渡しても通る。 */
  function formatCode(hex) {
    const clean = normalizeCode(hex);
    if (clean === null) { return String(hex == null ? '' : hex); }
    return (clean.match(/.{4}/g) || []).join(' ');
  }

  function checksumOf(bytes) {
    let checksum = 0;
    for (let i = 0; i < bytes.length; i++) { checksum ^= bytes[i]; }
    return checksum;
  }

  function buildPayload(codeHex) {
    const clean = normalizeCode(codeHex);
    if (clean === null) { return null; }
    const buffer = new ArrayBuffer(PAYLOAD_BYTES);
    const bytes = new Uint8Array(buffer);
    bytes[0] = MAGIC[0];
    bytes[1] = MAGIC[1];
    for (let i = 0; i < CODE_BYTES; i++) {
      bytes[MAGIC.length + i] = parseInt(clean.substr(i * 2, 2), 16);
    }
    bytes[PAYLOAD_BYTES - 1] = checksumOf(bytes.subarray(MAGIC.length, MAGIC.length + CODE_BYTES));
    return buffer;
  }

  /**
   * 受信済みバイト列の中から「MAGIC + 16バイト + 合うチェックサム」の並びを探す。
   * 見つかればコードの16進文字列、無ければnull。ノイズ由来のバイトが頭に
   * 混ざっていても、1バイトずつずらして探すので拾い直せる。
   */
  function scanForCode(bytes) {
    for (let i = 0; i + PAYLOAD_BYTES <= bytes.length; i++) {
      if (bytes[i] !== MAGIC[0] || bytes[i + 1] !== MAGIC[1]) { continue; }
      const code = bytes.subarray(i + MAGIC.length, i + MAGIC.length + CODE_BYTES);
      if (checksumOf(code) !== bytes[i + PAYLOAD_BYTES - 1]) { continue; }
      let hex = '';
      for (let k = 0; k < code.length; k++) { hex += code[k].toString(16).padStart(2, '0'); }
      return hex;
    }
    return null;
  }

  function isSupported() {
    return !!(window.Quiet && (window.AudioContext || window.webkitAudioContext));
  }

  /**
   * マイクが実際にあるかを確認する。enumerateDevices()は権限付与前でもkindは
   * 返す仕様なので、「APIがあるか」だけでなく「機器があるか」まで分かる。
   */
  function hasMicrophone() {
    if (!navigator.mediaDevices || typeof navigator.mediaDevices.enumerateDevices !== 'function') {
      return Promise.resolve(false);
    }
    return navigator.mediaDevices.enumerateDevices()
      .then((devices) => devices.some((d) => d.kind === 'audioinput'))
      .catch(() => false);
  }

  /**
   * Quiet.transmitter()を作る。
   *
   * quiet.jsは既定で「1フレームが音声バッファ(16384サンプル。ScriptProcessorの
   * 上限)をまたがない」ようにフレーム長を切り詰める(clampFrame)。ところが
   * 1シンボルに必要なサンプル数が多いプロファイル(bassやmosquitoの
   * samples_per_symbol=20など)では、プリアンブル+ヘッダだけで16384サンプルを
   * 超えてしまい、切り詰めた結果が0になって「frame_len<=0」で送信できなくなる
   * (実機で bass を選んだときに発生することを確認)。
   *
   * その場合はclampFrame:falseに切り替えて、切り詰めない方(プロファイル本来の
   * frame_length)で作り直す。フレームが音声バッファをまたぐぶん再生の途切れに
   * 弱くなるが、こちらが送るのは19バイト1フレームぶんだけで、しかも同じものを
   * 何度も繰り返すので実用上は問題にならない。送信されるビット列そのものは
   * どちらでも同じなので、受信側は何も変えなくてよい。
   */
  function createTransmitter(profile, onFinish) {
    const base = Object.assign({ profile: profile, onFinish: onFinish }, window.PrevarsiAudioProfiles.transmitOptsFor(profile));
    try {
      return Quiet.transmitter(base);
    } catch (e) {
      if (base.clampFrame === false) { throw e; }
      const message = e && e.message ? e.message : String(e);
      if (message.indexOf('frame_len') < 0) { throw e; }
      sonicLog('送信: frame_len<=0のためclampFrame:falseで作り直します profile=' + profile);
      return Quiet.transmitter(Object.assign({}, base, { clampFrame: false }));
    }
  }

  function sonicLog(...args) {
    try { console.log('[sonic]', ...args); } catch (e) { /* noop */ }
  }

  /**
   * 合図コードを音波で繰り返し流す。戻り値の stop() でいつでも止められる。
   * handlers: { onStatus(text), onDone(), onError(reason) }
   */
  function send(codeHex, profile, handlers) {
    const payload = buildPayload(codeHex);
    if (payload === null) {
      handlers.onError('合図コードの形が正しくありません。');
      return { stop() {} };
    }
    if (!window.Quiet) {
      handlers.onError('quiet.jsを読み込めませんでした。ページを再読み込みしてください。');
      return { stop() {} };
    }

    let stopped = false;
    let tx = null;
    let repeatTimer = null;
    let round = 0;

    const stop = () => {
      if (stopped) { return; }
      stopped = true;
      clearTimeout(repeatTimer);
      if (tx) {
        try { tx.destroy(); } catch (e) { /* noop */ }
        tx = null;
      }
    };

    Quiet.addReadyCallback(() => {
      if (stopped) { return; }
      try {
        tx = createTransmitter(profile, () => {
          if (stopped) { return; }
          if (round >= SEND_ROUNDS) {
            stop();
            handlers.onStatus('送信を終えました。相手が受け取れていないようなら、もう一度送ってください。');
            if (handlers.onDone) { handlers.onDone(); }
            return;
          }
          repeatTimer = setTimeout(() => {
            if (stopped || !tx) { return; }
            round++;
            handlers.onStatus('🔊 音波で送信中... (' + round + '/' + SEND_ROUNDS + '回目)');
            tx.transmit(payload);
          }, SEND_INTERVAL_MS);
        });
      } catch (e) {
        stop();
        handlers.onError(e && e.message ? e.message : String(e));
        return;
      }
      round = 1;
      handlers.onStatus('🔊 音波で送信中... (1/' + SEND_ROUNDS + '回目)');
      tx.transmit(payload);
    }, (reason) => {
      stop();
      handlers.onError('quiet.jsの初期化に失敗しました。' + (reason || ''));
    });

    return { stop };
  }

  /**
   * マイクで合図コードを待ち受ける。handlers: { onStatus(text), onCode(hex) }。
   * 戻り値はstop関数を解決するPromise(マイクを開始できなければreject)。
   * 1つ拾った時点でマイクを止めて終わる。
   */
  function listen(profile, handlers) {
    return new Promise((resolve, reject) => {
      if (!window.Quiet) {
        reject(new Error('quiet.jsを読み込めませんでした。'));
        return;
      }

      let accum = new Uint8Array(0);
      let rx = null;
      let destroyed = false;
      const stop = () => {
        if (destroyed) { return; }
        destroyed = true;
        if (rx) {
          try { rx.destroy(); } catch (e) { /* noop */ }
        }
        try { Quiet.disconnect(); } catch (e) { /* noop */ }
      };

      Quiet.addReadyCallback(() => {
        if (destroyed) { return; }
        handlers.onStatus('マイクの許可を確認しています...');
        try {
          rx = Quiet.receiver({
            profile: profile,
            onCreate: () => { handlers.onStatus('🎤 相手の音を聞いています...'); },
            onCreateFail: (reason) => { reject(new Error(reason)); },
            onReceiveFail: () => {
              // フレームが1つ壊れると、その先どれだけ正常に届いても組み立て中の
              // バイト列は永久に揃わない。溜め込んだものは捨てて、次の繰り返しの
              // 頭から拾い直す(送信側は同じコードを何度も流し続けている)。
              accum = new Uint8Array(0);
              handlers.onStatus('ノイズを検出しています。端末を近づけてみてください...');
            },
            onReceive: (payload) => {
              const chunk = new Uint8Array(payload);
              const merged = new Uint8Array(accum.length + chunk.length);
              merged.set(accum, 0);
              merged.set(chunk, accum.length);
              accum = merged.length > MAX_ACCUM_BYTES ? merged.subarray(merged.length - MAX_ACCUM_BYTES) : merged;

              const code = scanForCode(accum);
              if (code === null) {
                handlers.onStatus('🎤 相手の音を聞いています...');
                return;
              }
              // quiet.js内部のreadbuf()はwhile(true)ループの中でonReceiveを
              // 呼んでおり、このコールバックの中で同期的にrx.destroy()すると、
              // ループが次の周回で使うデコーダを解放済みにしてしまう
              // (use-after-free。p-chatでasm.jsのヒープ破壊→フリーズを確認済み)。
              // setTimeoutで次のタスクへ回し、ループが抜けきってから破棄する。
              setTimeout(() => {
                stop();
                handlers.onCode(code);
              }, 0);
            },
          });
        } catch (e) {
          reject(e);
          return;
        }
        resolve(stop);
      }, (reason) => { reject(new Error(reason)); });
    });
  }

  window.PrevarsiSonic = {
    CODE_HEX_LENGTH: CODE_HEX_LENGTH,
    isSupported: isSupported,
    hasMicrophone: hasMicrophone,
    normalizeCode: normalizeCode,
    formatCode: formatCode,
    send: send,
    listen: listen,
  };
})();
