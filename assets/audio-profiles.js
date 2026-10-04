(() => {
  'use strict';

  // =====================================================================
  // quiet.js(音波送受信)のプロファイル一覧・選択UI。
  //
  // p-chat/assets/scripts/audio-profiles.js からの移植で、選択肢の定義も
  // そこでの実機検証の結果をそのまま引き継いでいる。p-chatを参照せず
  // p-reversi単体で動かせるよう、意図的に別ファイルとして持っている
  // (localStorageもドメインが別で共有されないため、保存キーも自前のもの)。
  //
  // 選択肢は生の技術名(GMSK/周波数[Hz]等)ではなく、周波数帯ごとに「何が
  // 得意か」を短い言葉と表で示す形にしている。段階数を3つ等に絞っていないのは、
  // 理屈の上では妥当でも実機でしか分からない問題(wideband-dsssのフリーズ、
  // ultrasonicでのスマホの感度低下など)が過去に何度もあったため、実機で
  // 送受信できることを確認済みの選択肢はそのまま残してあるから。
  //
  // 到達距離の目安は実測値ではなく「低い周波数ほど空気中での減衰・スピーカーの
  // 指向性が弱く遠くまで届く」という物理特性からの見積もりに過ぎない。
  //
  // いずれもouter_fec_scheme:noneで、libfec.js(別途動的リンクが必要な
  // Reed-Solomon等)を要らないプロファイルのみを選んでいる。
  // =====================================================================
  const AUDIO_PROFILES = [
    {
      value: 'bass',
      priority: '飛びやすさ優先',
      freqLabel: '1kHz 〜 3kHz',
      audibilityLabel: 'はっきり聴こえる',
      rangeLabel: '数m 〜 10m超(目安)',
      // 1シンボルに20サンプル必要なため、1フレームが quiet.js の音声バッファ
      // (16384サンプル。ScriptProcessorの上限で、増やせない)に収まらず、
      // 既定のclampFrame(バッファをまたがないようフレーム長を切り詰める動作)が
      // 0を返して「frame_len<=0」で送信できなくなることを実機で確認した。
      // sonic.jsのcreateTransmitter()がその場合だけclampFrame:falseで作り直す
      // ので、ここで固定的にfalseにはしていない(切り詰められる環境ではそちらの
      // 方が再生の途切れに強いため)。
    },
    {
      value: 'audible',
      priority: '確実・推奨',
      freqLabel: '4kHz 前後',
      audibilityLabel: 'はっきり聴こえる',
      rangeLabel: '未実測(目安なし)',
    },
    {
      value: 'balanced',
      priority: 'バランス型(実機未検証)',
      freqLabel: '8kHz 〜 12kHz',
      audibilityLabel: '高い、耳につきやすい音',
      rangeLabel: '1m 〜 3m 程度(目安)',
    },
    {
      value: 'mosquito',
      priority: '中間・実績あり',
      freqLabel: '17kHz 前後',
      audibilityLabel: 'ほとんど聴こえない',
      rangeLabel: '未実測(目安なし)',
    },
    {
      value: 'wideband-dsss',
      priority: '要再検証(過去にクラッシュ報告あり)',
      freqLabel: '17kHz 前後(DSSS方式)',
      audibilityLabel: 'ほとんど聴こえない',
      rangeLabel: '未実測(目安なし)',
      // quiet.js側のバグ: transmitter()がencoderのframe_lenを検証せずtransmit()の
      // ループに使っており、DSSSのように拡散率が高くsampleBufferSize 1個に
      // シンボル1個も収まらない設定だとこれが0を返し、transmit()内のforループが
      // i+=0で前進せず無限ループ→メモリ食い潰しでクラッシュすることをp-chatの
      // 実機検証で確認した(vendor/quiet/quiet.jsにframe_len<=0の防御を追加済み)。
      // clampFrame:falseにすると quiet_encoder_get_frame_len を使う別経路に
      // 回避できるため、そちらで実際に送受信できるか再検証中。
      clampFrame: false,
    },
    {
      value: 'ultrasonic',
      priority: '静音性優先',
      freqLabel: '18.5kHz 〜 19.5kHz',
      audibilityLabel: 'ほぼ無音(非可聴)',
      rangeLabel: '10cm 〜 1m 程度(目安)',
    },
  ];
  const AUDIO_PROFILE_STORAGE_KEY = 'prevarsiAudioProfile';

  // 既定はaudible。並びは「飛びやすさ順」のままにしてあるが、先頭のbassは
  // 環境によってフレームが音声バッファに収まらず送信し直しが要る(bassの
  // コメント参照)ため、何も選ばずに使い始めた人には素直に通る方を当てる。
  const DEFAULT_PROFILE = 'audible';

  function loadAudioProfile() {
    try {
      const v = window.localStorage.getItem(AUDIO_PROFILE_STORAGE_KEY);
      if (AUDIO_PROFILES.some((p) => p.value === v)) { return v; }
    } catch (e) { /* noop */ }
    return DEFAULT_PROFILE;
  }

  function saveAudioProfile(v) {
    try { window.localStorage.setItem(AUDIO_PROFILE_STORAGE_KEY, v); } catch (e) { /* noop */ }
  }

  /**
   * Quiet.transmitter()へそのまま渡せる追加オプションを、選ばれたプロファイル
   * ごとに返す(通常は{}で、quiet.js側のデフォルト挙動のまま)。wideband-dsssの
   * clampFrame:falseのような既知の問題の回避だけをここに持たせ、呼び出し元が
   * プロファイルごとの事情を個別に知らなくて済むようにする。
   */
  function transmitOptsFor(value) {
    const p = AUDIO_PROFILES.find((x) => x.value === value);
    return (p && p.clampFrame !== undefined) ? { clampFrame: p.clampFrame } : {};
  }

  /** 選択肢の一覧表(優先度/周波数/聴こえるか/到達距離の目安)。参考として選択UIの下に添える。 */
  function buildAudioProfileTable() {
    const table = document.createElement('table');
    table.className = 'prevarsi-audio-profile-table';
    const thead = document.createElement('thead');
    const headRow = document.createElement('tr');
    ['重視すること', '周波数', '聴こえるか?', '到達距離の目安'].forEach((h) => {
      const th = document.createElement('th');
      th.textContent = h;
      headRow.appendChild(th);
    });
    thead.appendChild(headRow);
    table.appendChild(thead);

    const tbody = document.createElement('tbody');
    AUDIO_PROFILES.forEach((p) => {
      const row = document.createElement('tr');
      row.dataset.profileValue = p.value;
      [p.priority, p.freqLabel, p.audibilityLabel, p.rangeLabel].forEach((cell) => {
        const td = document.createElement('td');
        td.textContent = cell;
        row.appendChild(td);
      });
      tbody.appendChild(row);
    });
    table.appendChild(tbody);
    return table;
  }

  /**
   * 送信側・受信側の両方に同じ選択肢を出す。通信する双方は同じ方式を選ぶ必要が
   * ある。一覧表は<details>に畳んでおき(接続画面で最初から表を広げると本題の
   * 合図コードが埋もれるため)、開かなくても今どれを選んでいるかはselectの
   * 文言だけで分かるようにしてある。
   */
  function buildAudioProfileSelect() {
    const wrap = document.createElement('div');
    wrap.className = 'prevarsi-audio-profile-select';

    const label = document.createElement('label');
    const span = document.createElement('span');
    span.textContent = '音の方式(相手と同じものを選んでください): ';
    label.appendChild(span);
    const select = document.createElement('select');
    AUDIO_PROFILES.forEach((p) => {
      const opt = document.createElement('option');
      opt.value = p.value;
      opt.textContent = p.priority + '(' + p.freqLabel + ')';
      select.appendChild(opt);
    });
    select.value = loadAudioProfile();
    label.appendChild(select);
    wrap.appendChild(label);

    const details = document.createElement('details');
    const summary = document.createElement('summary');
    summary.textContent = '方式ごとの違いを見る';
    details.appendChild(summary);
    const table = buildAudioProfileTable();
    details.appendChild(table);
    wrap.appendChild(details);

    function syncHighlight() {
      table.querySelectorAll('tr[data-profile-value]').forEach((row) => {
        row.classList.toggle('prevarsi-audio-profile-row-selected', row.dataset.profileValue === select.value);
      });
    }
    syncHighlight();
    select.addEventListener('change', () => {
      saveAudioProfile(select.value);
      syncHighlight();
    });

    return { wrap, select };
  }

  window.PrevarsiAudioProfiles = {
    PROFILES: AUDIO_PROFILES,
    load: loadAudioProfile,
    save: saveAudioProfile,
    buildSelect: buildAudioProfileSelect,
    transmitOptsFor: transmitOptsFor,
  };
})();
