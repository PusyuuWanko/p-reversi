(() => {
  'use strict';

  // =====================================================================
  // プシューリバーシのブラウザ側。
  //
  // このファイルは「JavaScriptが動くときにしか使わないUI」を全部自分で
  // 組み立てる。index.phpが出力するのはJavaScript無しでも意味のあるHTML
  // (PRGフォームだけで完結する対局画面と設定)だけで、対局方式のタブ・
  // P2P対局の画面・その場で更新される盤面のHTMLはサーバー側に存在しない。
  // 起動すると #prevarsi-app に組み立てたUIを入れ、#prevarsi-nojs を隠す。
  // おかげでJS専用のHTMLを<noscript>で打ち消す必要が無くなっている。
  // =====================================================================

  // =====================================================================
  // オセロ判定エンジン(盤面8x8, 0=空, 1=黒, 2=白)
  // PMeikeeモードとコンピュータモードではサーバ側(index.php)にも同じ規則の
  // 実装があり、そちらが最終的な正となる。ここはP2Pモードの本番判定と、
  // 他モードでの合法手ハイライト用の下見に使う。
  // =====================================================================
  const SIZE = 8;
  const EMPTY = 0, BLACK = 1, WHITE = 2;
  const DIRS = [[-1,-1],[-1,0],[-1,1],[0,-1],[0,1],[1,-1],[1,0],[1,1]];

  function createInitialBoard() {
    const b = Array.from({length: SIZE}, () => Array(SIZE).fill(EMPTY));
    b[3][3] = WHITE; b[3][4] = BLACK; b[4][3] = BLACK; b[4][4] = WHITE;
    return b;
  }

  function inBounds(r, c) { return r >= 0 && r < SIZE && c >= 0 && c < SIZE; }
  function opponent(p) { return p === BLACK ? WHITE : BLACK; }

  function flipsForMove(board, player, row, col) {
    if (!inBounds(row, col) || board[row][col] !== EMPTY) return [];
    const opp = opponent(player);
    const flips = [];
    for (const [dr, dc] of DIRS) {
      const line = [];
      let r = row + dr, c = col + dc;
      while (inBounds(r, c) && board[r][c] === opp) { line.push([r, c]); r += dr; c += dc; }
      if (line.length > 0 && inBounds(r, c) && board[r][c] === player) flips.push(...line);
    }
    return flips;
  }

  function legalMoves(board, player) {
    const moves = [];
    for (let r = 0; r < SIZE; r++) for (let c = 0; c < SIZE; c++) {
      if (flipsForMove(board, player, r, c).length > 0) moves.push([r, c]);
    }
    return moves;
  }

  function applyMove(board, player, row, col) {
    const flips = flipsForMove(board, player, row, col);
    if (flips.length === 0) return null;
    const next = board.map(row => row.slice());
    next[row][col] = player;
    for (const [r, c] of flips) next[r][c] = player;
    return next;
  }

  function countStones(board) {
    let black = 0, white = 0;
    for (const row of board) for (const cell of row) {
      if (cell === BLACK) black++; else if (cell === WHITE) white++;
    }
    return { black, white };
  }

  /**
   * 手番を進める共通ロジック。着手後、次のプレイヤーに合法手が無ければ
   * 自動パスし、双方とも合法手が無ければ対局終了とする。
   * PHP側(othelloAdvanceTurn相当)と同じ規則。
   */
  function advanceTurn(board, movedPlayer) {
    const opp = opponent(movedPlayer);
    if (legalMoves(board, opp).length > 0) return { turn: opp, gameOver: false, passed: null };
    if (legalMoves(board, movedPlayer).length > 0) return { turn: movedPlayer, gameOver: false, passed: opp };
    return { turn: null, gameOver: true, passed: null };
  }

  function colorName(color) { return color === BLACK ? '黒' : '白'; }

  // =====================================================================
  // DOM組み立てヘルパー。
  //
  // 文字列を連結してinnerHTMLへ流し込む代わりに、テキストは必ずtextContentで
  // 入れる形にしてある。ユーザー名や相手から届いた文字列をそのまま扱っても
  // エスケープ漏れが起きないため。
  // =====================================================================
  function h(tag, props, children) {
    const node = document.createElement(tag);
    if (props) {
      for (const key of Object.keys(props)) {
        const value = props[key];
        if (value === null || value === undefined || value === false) { continue; }
        if (key === 'text') { node.textContent = value; }
        else if (key === 'class') { node.className = value; }
        else if (key === 'hidden') { node.hidden = true; }
        else if (key === 'disabled') { node.disabled = true; }
        else if (key === 'dataset') { Object.assign(node.dataset, value); }
        else if (key.slice(0, 2) === 'on') { node.addEventListener(key.slice(2).toLowerCase(), value); }
        else { node.setAttribute(key, value === true ? '' : value); }
      }
    }
    (children || []).forEach((child) => {
      if (child === null || child === undefined || child === false) { return; }
      node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
    });
    return node;
  }

  /** <select>を作る。optionsは[{value, label}]。valueは選択肢を入れた後で設定する。 */
  function buildSelect(props, options, selectedValue) {
    const select = h('select', props, options.map((o) => h('option', { value: String(o.value), text: o.label })));
    select.value = String(selectedValue);
    return select;
  }

  function replaceChildren(node, children) {
    node.textContent = '';
    (children || []).forEach((child) => { if (child) { node.appendChild(child); } });
  }

  // =====================================================================
  // 初期データ(index.phpが<script type="application/json">で埋めたもの)と
  // サーバとのやり取り。PMeikeeモード・P2Pの合図(signal_*)・コンピュータ
  // 対局(cpu_*)の3つとも同じ入口を使うので、CSRFトークンごとここに一本化する。
  // =====================================================================
  const bootstrap = (() => {
    const fallback = { user: null, csrf: '', accounts: {}, cpu: { levels: [], defaultLevel: 5 }, view: {}, lastMoveMarkCookie: 'prevarsi_last_move_mark' };
    const el = document.getElementById('prevarsi-bootstrap');
    if (!el) { return fallback; }
    try { return Object.assign(fallback, JSON.parse(el.textContent)); } catch (e) { return fallback; }
  })();

  function api(action, params, isPost) {
    const url = './?api=' + encodeURIComponent(action) + (!isPost && params ? '&' + new URLSearchParams(params).toString() : '');
    const opts = { credentials: 'same-origin' };
    if (isPost) {
      opts.method = 'POST';
      opts.headers = { 'Content-Type': 'application/x-www-form-urlencoded' };
      opts.body = new URLSearchParams(Object.assign({ csrf: bootstrap.csrf }, params || {})).toString();
    }
    return fetch(url, opts).then(r => r.json());
  }

  // =====================================================================
  // 表示と音の設定。
  //
  // 「直前に置かれた石の印」だけlocalStorageではなくcookieに置いている。
  // 盤面を描く場所がここ(JS版)とindex.php(JS無効版)の2箇所あり、どちらからも
  // 同じ1つの設定を読む必要があるため(localStorageはPHPから読めない)。
  // 音のオン/オフはJSでしか意味が無いので、notify-sound.js側の保存に任せる。
  // =====================================================================
  const Settings = (() => {
    const listeners = [];
    const cookieName = bootstrap.lastMoveMarkCookie || 'prevarsi_last_move_mark';

    function readCookie(name) {
      for (const part of String(document.cookie || '').split(';')) {
        const eq = part.indexOf('=');
        if (eq < 0) { continue; }
        if (part.slice(0, eq).trim() === name) { return decodeURIComponent(part.slice(eq + 1).trim()); }
      }
      return null;
    }

    function showLastMove() { return readCookie(cookieName) !== '0'; }

    function setShowLastMove(on) {
      document.cookie = cookieName + '=' + (on ? '1' : '0') +
        '; path=/; max-age=31536000; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
      emit();
    }

    function soundSupported() { return !!window.PusyuuNotifySound; }
    function soundOn() { return soundSupported() && !window.PusyuuNotifySound.isMuted(); }

    function setSoundOn(on) {
      if (soundSupported()) { window.PusyuuNotifySound.setMuted(!on); }
      emit();
    }

    function onChange(fn) { listeners.push(fn); }
    function emit() { listeners.forEach((fn) => fn()); }

    return { showLastMove, setShowLastMove, soundSupported, soundOn, setSoundOn, onChange };
  })();

  /** 自分が石を置いたときの短いクリック音。 */
  function playPlaceSound() {
    if (window.PusyuuNotifySound) { window.PusyuuNotifySound.playAction(); }
  }

  /** 相手(コンピュータ含む)が石を置いた・招待が届いた等の知らせの音。自分の着手音とは音色が違う。 */
  function playAlertSound() {
    if (window.PusyuuNotifySound) { window.PusyuuNotifySound.play(); }
  }

  /** 直前の手は {row,col,...} と [row,col] の両方の形で来る(サーバが返す場所ごとに違う)。 */
  function normalizeMove(move) {
    if (!move) { return null; }
    if (Array.isArray(move)) { return { row: move[0], col: move[1] }; }
    if (typeof move.row === 'number' && typeof move.col === 'number') { return { row: move.row, col: move.col }; }
    return null;
  }

  // =====================================================================
  // 盤面ビュー(3モード共通のレンダラ)
  // =====================================================================
  function createBoardView(container) {
    container.textContent = '';
    const grid = h('div', { class: 'othello-grid' });
    const cells = [];
    for (let r = 0; r < SIZE; r++) {
      for (let c = 0; c < SIZE; c++) {
        const cell = h('button', {
          type: 'button',
          class: 'cell',
          'aria-label': `${r + 1}行${c + 1}列`,
          dataset: { row: String(r), col: String(c) },
        });
        grid.appendChild(cell);
        cells.push(cell);
      }
    }
    container.appendChild(grid);

    let clickHandler = null;
    grid.addEventListener('click', (e) => {
      const cell = e.target.closest('.cell');
      if (!cell || !clickHandler) { return; }
      clickHandler(Number(cell.dataset.row), Number(cell.dataset.col));
    });

    let current = null;

    function paint() {
      if (!current) { return; }
      const legalSet = new Set((current.legal || []).map(([r, c]) => r + '_' + c));
      const mark = Settings.showLastMove() ? normalizeMove(current.lastMove) : null;
      for (const cell of cells) {
        const r = Number(cell.dataset.row), c = Number(cell.dataset.col);
        const v = current.board[r][c];
        let cls = 'cell';
        if (v === BLACK) cls += ' black'; else if (v === WHITE) cls += ' white';
        if (legalSet.has(r + '_' + c)) cls += ' legal';
        if (mark && mark.row === r && mark.col === c) cls += ' last-move';
        cell.className = cls;
      }
    }

    // 設定を切り替えたその場で印が出たり消えたりするようにしておく。
    Settings.onChange(paint);

    return {
      onCellClick(fn) { clickHandler = fn; },
      render(board, legal, lastMove) {
        current = { board, legal: legal || [], lastMove: lastMove || null };
        paint();
      },
    };
  }

  /** 見出し・説明・中身をひとまとまりにした区画。各モードの画面で使う。 */
  function section(titleText, children) {
    return h('div', { class: 'mode-section' }, [
      titleText ? h('h3', { class: 'section-title', text: titleText }) : null,
    ].concat(children || []));
  }

  // =====================================================================
  // PMeikeeモード(ログイン + ユーザー指定でのサーバ中継対局)
  // =====================================================================
  const PMeikeeMode = (() => {
    let els = {};
    let boardView = null;
    let currentMatchId = null;
    let pollTimer = null;
    let listPollTimer = null;
    let lastMatch = null;
    let matchTurnInitialized = false;
    // 対局一覧のポーリングで前回見た状態(match_id -> {status, my_turn})。
    // 「新しく招待が届いた」への遷移を検知するために使う。初回読み込み時点で
    // 既に溜まっていた分まで鳴らさないよう listInitialized で抑制する。
    const knownMatchStates = new Map();
    let listInitialized = false;

    function statusLabel(m) {
      switch (m.status) {
        case 'pending_sent': return '招待の返事待ち';
        case 'pending_received': return '招待が届いています';
        case 'active': return m.my_turn ? 'あなたの番です' : '相手の番です';
        case 'finished':
          if (m.winner === 0) return '引き分けで終了';
          return m.winner === m.my_color ? 'あなたの勝ちで終了' : '相手の勝ちで終了';
        default: return m.status;
      }
    }

    function renderList(matches) {
      if (matches.length === 0) {
        replaceChildren(els.matchList, [h('li', { class: 'empty-note', text: '対局はまだありません。相手のユーザー名を指定して招待してみましょう。' })]);
        return;
      }
      replaceChildren(els.matchList, matches.map((m) => {
        const actions = [];
        if (m.status === 'pending_received') {
          actions.push(h('button', { type: 'button', class: 'small-button', text: '受ける', onclick: () => respondInvite(m.match_id, 'accept') }));
          actions.push(h('button', { type: 'button', class: 'small-button ghost', text: '断る', onclick: () => respondInvite(m.match_id, 'decline') }));
        } else if (m.status === 'active' || m.status === 'finished') {
          actions.push(h('button', { type: 'button', class: 'small-button', text: '開く', onclick: () => openMatch(m.match_id) }));
        }
        return h('li', { class: 'match-row' }, [
          h('div', { class: 'match-row-info' }, [
            h('strong', { text: m.opponent.name || m.opponent.username }),
            h('span', { class: 'match-status', text: statusLabel(m) }),
          ]),
          h('div', { class: 'match-row-actions' }, actions),
        ]);
      }));
    }

    /**
     * 対局一覧の中から「新しく招待が届いた」という遷移を検知して音を鳴らす。
     * 相手が石を置いたことの知らせはrenderMatch側(より高頻度なポーリング)に
     * 任せる(でないと同じ出来事で二重に鳴ってしまう)。
     */
    function detectListChangesAndNotify(matches) {
      let shouldPlay = false;
      for (const m of matches) {
        const prev = knownMatchStates.get(m.match_id);
        if (listInitialized && m.status === 'pending_received' && (!prev || prev.status !== 'pending_received')) {
          shouldPlay = true;
        }
        knownMatchStates.set(m.match_id, { status: m.status, my_turn: m.my_turn });
      }
      listInitialized = true;
      if (shouldPlay) { playAlertSound(); }
    }

    function refreshList() {
      return api('list').then(res => {
        if (res.ok) {
          detectListChangesAndNotify(res.matches);
          renderList(res.matches);
        }
      }).catch(() => { /* 一時的な通信エラーは次のポーリングで拾い直す */ });
    }

    function respondInvite(matchId, action) {
      api(action, { match_id: matchId }, true).then(res => {
        if (!res.ok) { alert(res.message || '処理に失敗しました。'); }
        refreshList();
      }).catch(() => { alert('通信に失敗しました。'); });
    }

    function submitInvite(e) {
      e.preventDefault();
      const username = els.inviteUsername.value.trim();
      if (username === '') { return; }
      els.inviteMessage.textContent = '';
      api('invite', { opponent_username: username }, true).then(res => {
        els.inviteMessage.textContent = res.message || '';
        els.inviteMessage.className = res.ok ? 'form-message ok' : 'form-message error';
        if (res.ok) { els.inviteUsername.value = ''; refreshList(); }
      }).catch(() => {
        els.inviteMessage.textContent = '通信に失敗しました。';
        els.inviteMessage.className = 'form-message error';
      });
    }

    function openMatch(matchId) {
      currentMatchId = matchId;
      matchTurnInitialized = false;
      lastMatch = null;
      els.lobby.hidden = true;
      els.boardSection.hidden = false;
      if (!boardView) {
        boardView = createBoardView(els.boardContainer);
        boardView.onCellClick((r, c) => {
          if (!lastMatch || lastMatch.status !== 'active' || !lastMatch.my_turn) { return; }
          if (flipsForMove(lastMatch.board, lastMatch.my_color, r, c).length === 0) { return; }
          playPlaceSound();
          els.status.textContent = '送信中...';
          api('move', { match_id: currentMatchId, row: r, col: c }, true).then(res => {
            if (!res.ok) { alert(res.message || '着手できませんでした。'); }
            if (res.match) { renderMatch(res.match); } else { fetchMatchState(); }
          }).catch(() => { els.status.textContent = '通信に失敗しました。'; });
        });
      }
      fetchMatchState();
      clearInterval(pollTimer);
      pollTimer = setInterval(fetchMatchState, 2500);
    }

    function renderMatch(m) {
      // 相手の石が新しく置かれたときだけ知らせる。自分が置いたときは
      // クリックの瞬間に自分の着手音が鳴っているので、ここでは鳴らさない。
      const previous = lastMatch ? normalizeMove(lastMatch.last_move) : null;
      const current = normalizeMove(m.last_move);
      const byOpponent = !!(m.last_move && m.last_move.color !== undefined && m.last_move.color !== m.my_color);
      const isNew = current !== null && (previous === null || previous.row !== current.row || previous.col !== current.col);
      if (matchTurnInitialized && byOpponent && isNew) { playAlertSound(); }

      lastMatch = m;
      matchTurnInitialized = true;

      const legal = (m.status === 'active' && m.my_turn) ? legalMoves(m.board, m.my_color) : [];
      boardView.render(m.board, legal, m.last_move);

      const score = countStones(m.board);
      els.score.textContent = `黒 ${score.black} - 白 ${score.white}`;
      els.players.textContent =
        `あなた: ${colorName(m.my_color)}(${m.my_color === BLACK ? m.black.name : m.white.name}) / ` +
        `相手: ${colorName(opponent(m.my_color))}(${m.my_color === BLACK ? m.white.name : m.black.name})`;

      if (m.status === 'finished') {
        clearInterval(pollTimer);
        if (m.winner === 0) { els.status.textContent = '引き分けです。'; }
        else { els.status.textContent = (m.winner === m.my_color) ? 'あなたの勝ちです!' : '相手の勝ちです。'; }
        els.resign.hidden = true;
      } else {
        els.status.textContent = m.my_turn ? 'あなたの番です。石を置けるマスが光っています。' : '相手の番です。しばらくお待ちください。';
        els.resign.hidden = false;
      }
    }

    function fetchMatchState() {
      if (!currentMatchId) { return; }
      api('match_state', { match_id: currentMatchId }).then(res => {
        if (res.ok) { renderMatch(res.match); }
      }).catch(() => { /* 一時的な通信エラーは次のポーリングで拾い直す */ });
    }

    function backToLobby() {
      currentMatchId = null;
      lastMatch = null;
      clearInterval(pollTimer);
      els.boardSection.hidden = true;
      els.lobby.hidden = false;
      refreshList();
    }

    function resign() {
      if (!currentMatchId) { return; }
      if (!confirm('投了しますか?')) { return; }
      api('resign', { match_id: currentMatchId }, true).then(res => {
        if (res.ok && res.match) { renderMatch(res.match); }
      }).catch(() => { /* 失敗しても対局はそのまま続けられる */ });
    }

    function build() {
      if (!bootstrap.user) {
        return h('div', { class: 'mode-body' }, [
          h('p', { class: 'mode-lead', text: 'プシューメイキィにログインすると、相手のユーザー名を指定して対局へ招待できます。着手はこのサーバを経由して相手に届きます。' }),
          h('p', {}, [h('a', { class: 'button', href: bootstrap.accounts.login || '#', text: 'ログイン' })]),
          h('p', { class: 'note' }, [
            'プシューメイキィをお持ちでない場合は',
            h('a', { href: bootstrap.accounts.create || '#', text: 'こちらで作成' }),
            'してください。',
          ]),
        ]);
      }

      els = {};
      els.inviteUsername = h('input', { type: 'text', id: 'invite-username', autocomplete: 'off', placeholder: 'ユーザー名', required: true });
      els.inviteMessage = h('p', { class: 'form-message', role: 'status' });
      els.matchList = h('ul', { class: 'match-list' });

      els.lobby = h('div', {}, [
        h('p', { class: 'mode-lead', text: `${bootstrap.user.name}さん(${bootstrap.user.username})としてログイン中です。相手のユーザー名を指定して招待してください。` }),
        h('form', { class: 'invite-form', onsubmit: submitInvite }, [
          h('label', { for: 'invite-username', text: '対戦相手のユーザー名' }),
          els.inviteUsername,
          h('button', { type: 'submit', text: '招待する' }),
          els.inviteMessage,
        ]),
        section('対局一覧', [els.matchList]),
      ]);

      els.players = h('p', { class: 'players-label' });
      els.score = h('p', { class: 'score-label' });
      els.boardContainer = h('div', { class: 'board-container' });
      els.status = h('p', { class: 'status-label', role: 'status' });
      els.resign = h('button', { type: 'button', class: 'small-button ghost', text: '投了する', onclick: resign });

      els.boardSection = h('div', { hidden: true }, [
        h('div', { class: 'button-row' }, [
          h('button', { type: 'button', class: 'small-button ghost', text: '← ロビーに戻る', onclick: backToLobby }),
        ]),
        els.players,
        els.score,
        els.boardContainer,
        els.status,
        h('div', { class: 'button-row' }, [els.resign]),
      ]);

      return h('div', { class: 'mode-body' }, [els.lobby, els.boardSection]);
    }

    function activate() {
      if (!bootstrap.user) { return; }
      if (currentMatchId) {
        clearInterval(pollTimer);
        pollTimer = setInterval(fetchMatchState, 2500);
        return;
      }
      refreshList();
      clearInterval(listPollTimer);
      listPollTimer = setInterval(refreshList, 4000);
    }

    function deactivate() {
      clearInterval(listPollTimer);
      clearInterval(pollTimer);
    }

    /** JS無効版のリンクや共有URL(?view=match&match_id=...)から開かれた場合の入口。 */
    function openSharedMatch(matchId) {
      if (bootstrap.user && matchId) { openMatch(matchId); }
    }

    return { build, activate, deactivate, openSharedMatch };
  })();

  // =====================================================================
  // P2Pモード(ログイン不要、WebRTCで直接つなぐ)
  //
  // つなぐときの合図だけを短い「合図コード」にしてサーバへ一時的に預け、
  // 音波(quiet.js)ではそのコードだけを飛ばす。offer/answer そのもの
  // (base64で2〜4KB)は音響モデムの1フレーム(25〜34バイト)に対して
  // 100フレーム以上になり、フレームが1つ欠けると組み立て中のデータを
  // 丸ごと捨てる仕様のため、音波には載せられない(index.phpのシグナリング節と
  // assets/sonic.js冒頭のコメントも参照)。
  //
  // サーバを一切使わない従来のコピー&ペースト経路も畳んだ状態で併設して
  // あり、どちらの経路も同じ pc(RTCPeerConnection)に対して働くため、
  // 途中でどちらに乗り換えても構わない。
  // =====================================================================
  const P2PMode = (() => {
    let els = {};
    let pc = null, channel = null;
    let boardView = null;
    let board = null, turn = BLACK, myColor = null, gameOver = false;
    let lastMove = null;
    let rematchRequestedByMe = false, rematchRequestedByPeer = false;

    let signalCode = null;      // サーバに預けている合図のコード(ホスト側だけ)
    let answerPollTimer = null; // 相手の返事が届いたかを見に行くタイマー(ホスト側だけ)
    let sonicSender = null;     // 送信中の音波。stop()で止める
    let sonicListenStop = null; // 受信中の音波を止める関数
    let guestListening = false;
    let hostProfile = null, guestProfile = null; // audio-profiles.jsが作る{wrap, select}
    let sonicAvailable = false;

    // ICEサーバーはindex.php側の設定(prevarsiIceServers())をそのまま使う。
    // 空のときはSTUNなしで動くことになり、同じネットワークの中にいる相手としか
    // つながらない。その前提を案内文とエラー文言に反映する。
    const ICE_SERVERS = Array.isArray(bootstrap.p2p && bootstrap.p2p.iceServers) ? bootstrap.p2p.iceServers : [];
    const CAN_CROSS_NETWORK = !!(bootstrap.p2p && bootstrap.p2p.crossNetwork);

    const REACH_NOTE = CAN_CROSS_NETWORK
      ? '離れた場所にいる相手ともつながります。'
      : 'このサーバーではSTUNを使わない設定のため、つながるのは同じネットワーク(同じWi-Fiなど)にいる相手だけです。';

    function encodeDesc(desc) { return btoa(JSON.stringify(desc)); }
    function decodeDesc(str) { return JSON.parse(atob(String(str).trim())); }

    function waitIceGatheringComplete(peer) {
      return new Promise((resolve) => {
        if (peer.iceGatheringState === 'complete') { resolve(); return; }
        function check() {
          if (peer.iceGatheringState === 'complete') {
            peer.removeEventListener('icegatheringstatechange', check);
            resolve();
          }
        }
        peer.addEventListener('icegatheringstatechange', check);
      });
    }

    // 合図コードの整形/検証はsonic.js側に持たせているが、そちらの読み込みに
    // 失敗しても「音波が使えないだけ」で手入力の経路までは壊さないよう、
    // ここに同じ規則の控えを置いておく。
    function formatCode(code) {
      return window.PrevarsiSonic ? window.PrevarsiSonic.formatCode(code) : String(code == null ? '' : code);
    }

    function normalizeCode(code) {
      if (window.PrevarsiSonic) { return window.PrevarsiSonic.normalizeCode(code); }
      const clean = String(code == null ? '' : code).replace(/[^0-9a-fA-F]/g, '').toLowerCase();
      return clean.length === 32 ? clean : null;
    }

    function stopSonicSend() {
      if (sonicSender) {
        sonicSender.stop();
        sonicSender = null;
      }
      if (els.hostPlay) { els.hostPlay.textContent = '🔊 音波で送る'; }
    }

    function stopSonicListen() {
      guestListening = false;
      if (sonicListenStop) {
        sonicListenStop();
        sonicListenStop = null;
      }
      if (els.guestListen) { els.guestListen.textContent = '🎤 音波で受け取る'; }
    }

    function stopAnswerPoll() {
      clearInterval(answerPollTimer);
      answerPollTimer = null;
    }

    /**
     * 預けた合図をサーバから片付ける。呼ばなくてもTTLで消えるが、待たずに消す。
     * ページを閉じる途中のfetchは中断されることがあるため、そのときだけ
     * sendBeaconに切り替える(PHPの$_POSTに載るよう、フォーム形式のBlobで送る)。
     */
    function releaseSignal(useBeacon) {
      if (!signalCode) { return; }
      const code = signalCode;
      signalCode = null;
      if (useBeacon && navigator.sendBeacon) {
        const body = new URLSearchParams({ csrf: bootstrap.csrf, code: code }).toString();
        navigator.sendBeacon('./?api=signal_cancel', new Blob([body], { type: 'application/x-www-form-urlencoded' }));
        return;
      }
      api('signal_cancel', { code: code }, true).catch(() => {});
    }

    /** 接続の失敗を、いま表示されている側のメッセージ欄に伝える。 */
    function reportConnectionFailure() {
      const target = myColor === BLACK ? els.hostMessage : els.guestMessage;
      if (!target) { return; }
      target.textContent = '接続できませんでした。お互いが同じネットワーク(同じWi-Fiなど)にいるか確認してください。' +
        (CAN_CROSS_NETWORK ? '' : ' 別々のネットワークにいる場合、現在の設定ではつなげません。');
    }

    /**
     * ICEの接続が失敗したことを拾う。相手が別のネットワークにいてSTUNが無い、
     * といった場合でもoffer/answerの交換自体は成功してしまうため、何も見張って
     * いないと画面は「接続しています...」のまま黙って止まる。原因の見当がつく
     * 文言を出して、待ち続けずに済むようにする。
     */
    function watchIceFailure(peer) {
      peer.addEventListener('iceconnectionstatechange', () => {
        if (peer.iceConnectionState === 'failed') { reportConnectionFailure(); }
      });
    }

    /** 接続の失敗を、いま表示されている側のメッセージ欄に伝える。 */
    function reportConnectionFailure() {
      const target = myColor === BLACK ? els.hostMessage : els.guestMessage;
      if (!target) { return; }
      target.textContent = '接続できませんでした。' +
        (CAN_CROSS_NETWORK
          ? 'ネットワークの状態によってはつながらないことがあります。もう一度やり直してみてください。'
          : 'お互いが同じネットワーク(同じWi-Fiなど)にいるか確認してください。別々のネットワークにいる場合、現在の設定ではつなげません。');
    }

    /**
     * ICEの接続が失敗したことを拾う。相手が別のネットワークにいてSTUNが効いて
     * いない、といった場合でも合図の交換自体は成功してしまうため、何も見張って
     * いないと画面は「接続しています...」のまま黙って止まる。原因の見当がつく
     * 文言を出して、待ち続けずに済むようにする。
     */
    function watchIceFailure(peer) {
      peer.addEventListener('iceconnectionstatechange', () => {
        if (peer.iceConnectionState === 'failed') { reportConnectionFailure(); }
      });
    }

    function resetPeer() {
      // 接続をやり直すときは、鳴らしっぱなし・聞きっぱなし・預けっぱなしを
      // まとめて片付けてから始める。
      stopSonicSend();
      stopSonicListen();
      stopAnswerPoll();
      releaseSignal();
      if (pc) { try { pc.close(); } catch (e) {} }
      pc = null; channel = null; board = null; gameOver = false; lastMove = null;
      rematchRequestedByMe = false; rematchRequestedByPeer = false;
    }

    function setupChannel() {
      channel.addEventListener('open', () => {
        // つながった時点で合図はもう要らない。音波も止め、預けた合図も消す。
        stopSonicSend();
        stopSonicListen();
        stopAnswerPoll();
        releaseSignal();
        startNewGame();
        els.setupBlock.hidden = true;
        els.boardSection.hidden = false;
        els.status.textContent = `接続しました。あなたは${colorName(myColor)}です。`;
      });
      channel.addEventListener('close', () => {
        els.status.textContent = '接続が切断されました。';
      });
      channel.addEventListener('message', (e) => {
        let msg;
        try { msg = JSON.parse(e.data); } catch (err) { return; }
        handlePeerMessage(msg);
      });
    }

    function handlePeerMessage(msg) {
      if (msg.type === 'move') {
        if (gameOver || turn !== opponent(myColor)) { return; }
        const next = applyMove(board, opponent(myColor), msg.row, msg.col);
        if (!next) { return; }
        board = next;
        lastMove = { row: msg.row, col: msg.col, color: opponent(myColor) };
        playAlertSound(); // 相手が石を置いた
        finishTurnAdvance(advanceTurn(board, opponent(myColor)));
      } else if (msg.type === 'resign') {
        gameOver = true;
        renderState();
        els.status.textContent = '相手が投了しました。あなたの勝ちです。';
        els.resign.hidden = true;
      } else if (msg.type === 'rematch_request') {
        rematchRequestedByPeer = true;
        if (rematchRequestedByMe) { doRematch(); }
        else {
          els.status.textContent = '相手がもう一度対局したいと言っています。';
          els.rematch.textContent = 'もう一度対局する(相手が待っています)';
        }
      }
    }

    function finishTurnAdvance(adv) {
      if (adv.gameOver) { gameOver = true; } else { turn = adv.turn; }
      renderState();
      if (gameOver) { announceResult(); }
      else if (adv.passed !== null) {
        els.status.textContent = (adv.passed === myColor ? 'あなた' : '相手') + 'は置ける場所が無いためパスになりました。';
      }
    }

    function announceResult() {
      const score = countStones(board);
      if (score.black === score.white) {
        els.status.textContent = `引き分けです(黒${score.black} - 白${score.white})。`;
      } else {
        const winner = score.black > score.white ? BLACK : WHITE;
        els.status.textContent = (winner === myColor ? 'あなたの勝ちです! ' : '相手の勝ちです。') + `(黒${score.black} - 白${score.white})`;
      }
      els.resign.hidden = true;
    }

    function startNewGame() {
      board = createInitialBoard();
      turn = BLACK;
      gameOver = false;
      lastMove = null;
      rematchRequestedByMe = false;
      rematchRequestedByPeer = false;
      els.rematch.hidden = true;
      els.rematch.textContent = 'もう一度対局する';
      els.resign.hidden = false;
      if (!boardView) {
        boardView = createBoardView(els.boardContainer);
        boardView.onCellClick((r, c) => {
          if (gameOver || turn !== myColor || !channel || channel.readyState !== 'open') { return; }
          if (flipsForMove(board, myColor, r, c).length === 0) { return; }
          playPlaceSound();
          board = applyMove(board, myColor, r, c);
          lastMove = { row: r, col: c, color: myColor };
          channel.send(JSON.stringify({ type: 'move', row: r, col: c }));
          finishTurnAdvance(advanceTurn(board, myColor));
        });
      }
      renderState();
    }

    function renderState() {
      const legal = (!gameOver && turn === myColor) ? legalMoves(board, myColor) : [];
      boardView.render(board, legal, lastMove);
      const score = countStones(board);
      els.score.textContent = `黒 ${score.black} - 白 ${score.white}`;
      els.players.textContent = `あなた: ${colorName(myColor)} / 相手: ${colorName(opponent(myColor))}`;
      if (!gameOver) {
        els.status.textContent = turn === myColor ? 'あなたの番です。石を置けるマスが光っています。' : '相手の番です。しばらくお待ちください。';
        els.rematch.hidden = true;
      } else {
        els.rematch.hidden = false;
      }
    }

    function doRematch() {
      startNewGame();
      els.status.textContent = `新しい対局を始めました。あなたは${colorName(myColor)}です。`;
    }

    function requestRematch() {
      rematchRequestedByMe = true;
      if (channel && channel.readyState === 'open') { channel.send(JSON.stringify({ type: 'rematch_request' })); }
      if (rematchRequestedByPeer) { doRematch(); }
      else {
        els.status.textContent = '相手の応答を待っています...';
        els.rematch.textContent = 'もう一度対局する(応答待ち)';
      }
    }

    function resign() {
      if (gameOver || !channel || channel.readyState !== 'open') { return; }
      if (!confirm('投了しますか?')) { return; }
      channel.send(JSON.stringify({ type: 'resign' }));
      gameOver = true;
      renderState();
      els.status.textContent = 'あなたは投了しました。相手の勝ちです。';
      els.resign.hidden = true;
    }

    /** ホストが2秒おきに「相手の返事が届いたか」を見に行く。 */
    function startAnswerPoll() {
      stopAnswerPoll();
      answerPollTimer = setInterval(() => {
        if (!signalCode) { stopAnswerPoll(); return; }
        api('signal_poll', { code: signalCode }).then(res => {
          if (!res.ok) {
            stopAnswerPoll();
            els.hostMessage.textContent = res.message || '合図の有効期限が切れました。もう一度作り直してください。';
            return;
          }
          if (res.state !== 'answered') { return; }
          stopAnswerPoll();
          stopSonicSend();
          signalCode = null; // サーバ側は返事を渡した時点で削除済み
          els.hostMessage.textContent = '相手が見つかりました。接続しています...';
          applyAnswer(res.answer, (msg) => { els.hostMessage.textContent = msg; });
        }).catch(() => { /* 一時的な通信エラーは次回のポーリングで拾い直す */ });
      }, 2000);
    }

    function applyAnswer(encoded, showError) {
      let answerDesc;
      try { answerDesc = decodeDesc(encoded); } catch (err) {
        showError('接続情報を解釈できませんでした。もう一度最初からやり直してください。');
        return;
      }
      pc.setRemoteDescription(answerDesc).catch(() => {
        showError('接続に失敗しました。もう一度最初からやり直してください。');
      });
    }

    function startHost() {
      resetPeer();
      myColor = BLACK;
      pc = new RTCPeerConnection({ iceServers: ICE_SERVERS });
      watchIceFailure(pc);
      channel = pc.createDataChannel('othello');
      setupChannel();
      els.roleChoice.hidden = true;
      els.guestBlock.hidden = true;
      els.hostBlock.hidden = false;
      els.hostCode.textContent = '接続情報を作成しています...';
      els.hostCopy.disabled = true;
      els.hostPlay.disabled = true;
      els.hostMessage.textContent = '';
      els.hostManualMessage.textContent = '';
      els.offerOutput.value = '接続情報を作成しています...';

      pc.createOffer()
        .then(offer => pc.setLocalDescription(offer))
        .then(() => waitIceGatheringComplete(pc))
        .then(() => {
          const offer = encodeDesc(pc.localDescription);
          els.offerOutput.value = offer;
          return api('signal_create', { offer: offer }, true);
        })
        .then(res => {
          if (!res.ok) {
            els.hostCode.textContent = '(発行できませんでした)';
            els.hostMessage.textContent = (res.message || '合図コードを発行できませんでした。') +
              ' 下の「接続文字列を直接やり取りする」からならこのままつなげます。';
            return;
          }
          signalCode = res.code;
          els.hostCode.textContent = formatCode(res.code);
          els.hostCopy.disabled = false;
          els.hostPlay.disabled = !sonicAvailable;
          els.hostMessage.textContent = sonicAvailable
            ? '「🔊 音波で送る」を押すか、コードを読み上げて相手に伝えてください。'
            : 'コードを読み上げるなどして相手に伝えてください。';
          startAnswerPoll();
        })
        .catch(() => {
          els.hostCode.textContent = '(発行できませんでした)';
          els.hostMessage.textContent = 'サーバに接続できませんでした。下の「接続文字列を直接やり取りする」からならこのままつなげます。';
        });
    }

    function toggleHostSonicSend() {
      if (sonicSender) {
        stopSonicSend();
        els.hostMessage.textContent = '送信を止めました。相手が受け取れていないときはもう一度送ってください。';
        return;
      }
      if (!signalCode) { return; }
      els.hostPlay.textContent = '⏹ 送信を止める';
      sonicSender = window.PrevarsiSonic.send(signalCode, hostProfile.select.value, {
        onStatus: (text) => { els.hostMessage.textContent = text; },
        onDone: () => { sonicSender = null; els.hostPlay.textContent = '🔊 音波で送る'; },
        onError: (reason) => {
          sonicSender = null;
          els.hostPlay.textContent = '🔊 音波で送る';
          els.hostMessage.textContent = '音波で送れませんでした。' + (reason || '') +
            ' コードを読み上げて相手に入力してもらってください。';
        },
      });
    }

    function startGuest() {
      resetPeer();
      myColor = WHITE;
      els.roleChoice.hidden = true;
      els.hostBlock.hidden = true;
      els.guestBlock.hidden = false;
      els.guestCode.value = '';
      els.guestManualMessage.textContent = '';
      els.guestMessage.textContent = sonicAvailable
        ? '相手の画面で「🔊 音波で送る」を押してもらい、こちらで「🎤 音波で受け取る」を押してください。'
        : '相手に合図コードを読み上げてもらい、下の欄に入力してください。';
    }

    function toggleGuestListen() {
      if (guestListening) {
        stopSonicListen();
        els.guestMessage.textContent = '聞き取りを止めました。';
        return;
      }
      guestListening = true;
      els.guestListen.textContent = '⏹ 聞き取りを止める';
      window.PrevarsiSonic.listen(guestProfile.select.value, {
        onStatus: (text) => { if (guestListening) { els.guestMessage.textContent = text; } },
        onCode: (code) => {
          guestListening = false;
          sonicListenStop = null;
          els.guestListen.textContent = '🎤 音波で受け取る';
          els.guestCode.value = formatCode(code);
          connectWithCode(code);
        },
      }).then((stop) => {
        // 準備が整うまでの間に止められていた場合は、開いたそばから閉じる。
        if (!guestListening) { stop(); return; }
        sonicListenStop = stop;
      }).catch((err) => {
        guestListening = false;
        sonicListenStop = null;
        els.guestListen.textContent = '🎤 音波で受け取る';
        els.guestMessage.textContent = 'マイクを開始できませんでした。' + (err && err.message ? err.message : '') +
          ' 合図コードを直接入力してください。';
      });
    }

    /** offerを受け取ってanswerを作る。合図コード経路と手動ペースト経路の共通部分。 */
    function buildAnswer(offerDesc) {
      // コードを打ち間違えた等でやり直すと、ここは何度も呼ばれ得る。前回の接続を
      // 閉じずに作り直すと、古い接続が繋がったまま残ってしまう。
      if (pc) { try { pc.close(); } catch (e) {} }
      pc = new RTCPeerConnection({ iceServers: ICE_SERVERS });
      watchIceFailure(pc);
      pc.ondatachannel = (e) => { channel = e.channel; setupChannel(); };
      return pc.setRemoteDescription(offerDesc)
        .then(() => pc.createAnswer())
        .then(answer => pc.setLocalDescription(answer))
        .then(() => waitIceGatheringComplete(pc))
        .then(() => encodeDesc(pc.localDescription));
    }

    function connectWithCode(code) {
      const clean = normalizeCode(code);
      if (clean === null) {
        els.guestMessage.textContent = '合図コードは32桁の英数字です。読み間違いや入力漏れがないか確認してください。';
        return;
      }
      els.guestMessage.textContent = '相手の接続情報を受け取っています...';
      api('signal_fetch', { code: clean }, true)
        .then(res => {
          if (!res.ok) {
            els.guestMessage.textContent = res.message || '相手が見つかりませんでした。';
            return null;
          }
          let offerDesc;
          try { offerDesc = decodeDesc(res.offer); } catch (err) {
            els.guestMessage.textContent = '接続情報を解釈できませんでした。もう一度やり直してください。';
            return null;
          }
          els.guestMessage.textContent = '接続情報を作っています...';
          return buildAnswer(offerDesc).then(answer => api('signal_answer', { code: clean, answer: answer }, true));
        })
        .then(res => {
          if (!res) { return; }
          if (!res.ok) {
            els.guestMessage.textContent = res.message || '接続情報を返せませんでした。';
            return;
          }
          els.guestMessage.textContent = '相手に返事を届けました。まもなく対局が始まります...';
        })
        .catch(() => { els.guestMessage.textContent = '接続に失敗しました。もう一度やり直してください。'; });
    }

    function guestSubmitOffer(e) {
      e.preventDefault();
      let offerDesc;
      try { offerDesc = decodeDesc(els.offerInput.value); } catch (err) {
        els.guestManualMessage.textContent = '接続文字列を解釈できませんでした。コピーミスがないか確認してください。';
        return;
      }
      els.guestManualMessage.textContent = '';
      buildAnswer(offerDesc)
        .then(answer => { els.answerOutputBlock.hidden = false; els.answerOutput.value = answer; })
        .catch(() => { els.guestManualMessage.textContent = '接続情報の作成に失敗しました。もう一度やり直してください。'; });
    }

    function hostSubmitAnswer(e) {
      e.preventDefault();
      els.hostManualMessage.textContent = '';
      stopAnswerPoll();
      applyAnswer(els.answerInput.value, (msg) => { els.hostManualMessage.textContent = msg; });
    }

    function backToStart() {
      resetPeer();
      els.boardSection.hidden = true;
      els.setupBlock.hidden = false;
      els.roleChoice.hidden = false;
      els.hostBlock.hidden = true;
      els.guestBlock.hidden = true;
      els.answerOutputBlock.hidden = true;
    }

    function copyField(field) {
      field.select();
      field.setSelectionRange(0, field.value.length);
      try { document.execCommand('copy'); } catch (e) {}
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(field.value).catch(() => {});
      }
    }

    /** 入力欄に入っていない文字列のコピー。一時的なtextareaを挟んで同じ手順に載せる。 */
    function copyText(text) {
      if (!text) { return; }
      const temp = h('textarea', { readonly: true });
      temp.value = text;
      temp.style.position = 'fixed';
      temp.style.opacity = '0';
      document.body.appendChild(temp);
      copyField(temp);
      temp.remove();
    }

    /** 非対応の場合、disabled+titleではなく常に見えるテキストで理由を示す。 */
    function unavailableNote(message) {
      return h('p', { class: 'note unavailable-note', text: message });
    }

    function buildHostBlock() {
      els.hostCode = h('p', { class: 'p2p-code', text: '接続情報を作成しています...' });
      els.hostCopy = h('button', { type: 'button', class: 'small-button ghost', text: 'コードをコピー', disabled: true, onclick: () => copyText(formatCode(signalCode || '')) });
      els.hostPlay = h('button', { type: 'button', class: 'small-button', text: '🔊 音波で送る', disabled: true, onclick: toggleHostSonicSend });
      els.hostProfileSlot = h('div', { class: 'p2p-profile-slot' });
      els.hostMessage = h('p', { class: 'status-label', role: 'status' });

      els.offerOutput = h('textarea', { readonly: true, rows: '4' });
      els.answerInput = h('textarea', { rows: '4', required: true });
      els.hostManualMessage = h('p', { class: 'form-message error', role: 'status' });

      els.hostBlock = h('div', { hidden: true }, [
        section('① 合図コードを相手に渡してください', [
          els.hostCode,
          h('div', { class: 'button-row' }, [els.hostCopy, els.hostPlay]),
          els.hostProfileSlot,
        ]),
        section('② 相手が受け取ると自動的に始まります', [
          els.hostMessage,
          h('div', { class: 'button-row' }, [
            h('button', { type: 'button', class: 'small-button ghost', text: 'キャンセル', onclick: backToStart }),
          ]),
        ]),
        h('details', { class: 'p2p-manual' }, [
          h('summary', { text: 'うまくいかないとき: 接続文字列を直接やり取りする(このサーバを一切使いません)' }),
          h('p', { class: 'note', text: '合図コードは、接続の合図(offer/answer)だけをこのサーバに一時的に預けることで実現しています。それも避けたい場合や、サーバに繋がらない場合は、代わりに下の長い文字列をそのまま相手に渡してください。' }),
          h('h4', { class: 'section-title', text: 'この文字列を相手に送る' }),
          els.offerOutput,
          h('div', { class: 'button-row' }, [
            h('button', { type: 'button', class: 'small-button', text: 'コピーする', onclick: () => copyField(els.offerOutput) }),
          ]),
          h('h4', { class: 'section-title', text: '相手から返ってきた文字列を貼り付ける' }),
          h('form', { onsubmit: hostSubmitAnswer }, [
            els.answerInput,
            h('div', { class: 'button-row' }, [h('button', { type: 'submit', text: '接続する' })]),
            els.hostManualMessage,
          ]),
        ]),
      ]);
      return els.hostBlock;
    }

    function buildGuestBlock() {
      els.guestListen = h('button', { type: 'button', class: 'small-button', text: '🎤 音波で受け取る', onclick: toggleGuestListen });
      els.guestProfileSlot = h('div', { class: 'p2p-profile-slot' });
      els.guestCode = h('input', { type: 'text', id: 'p2p-guest-code', class: 'code-input', autocomplete: 'off', spellcheck: 'false' });
      els.guestMessage = h('p', { class: 'status-label', role: 'status' });

      els.offerInput = h('textarea', { rows: '4', required: true });
      els.guestManualMessage = h('p', { class: 'form-message error', role: 'status' });
      els.answerOutput = h('textarea', { readonly: true, rows: '4' });
      els.answerOutputBlock = h('div', { hidden: true }, [
        h('h4', { class: 'section-title', text: 'この文字列を相手に送り返す' }),
        els.answerOutput,
        h('div', { class: 'button-row' }, [
          h('button', { type: 'button', class: 'small-button', text: 'コピーする', onclick: () => copyField(els.answerOutput) }),
        ]),
        h('p', { class: 'note', text: '相手が貼り付けると自動的に対局が始まります。' }),
      ]);

      els.guestBlock = h('div', { hidden: true }, [
        section('① 相手の合図コードを受け取ってください', [
          h('div', { class: 'button-row' }, [els.guestListen]),
          els.guestProfileSlot,
          h('form', { class: 'invite-form', onsubmit: (e) => { e.preventDefault(); connectWithCode(els.guestCode.value); } }, [
            h('label', { for: 'p2p-guest-code', text: '合図コード(音波が使えないときは直接入力・貼り付け)' }),
            els.guestCode,
            h('button', { type: 'submit', text: 'このコードでつなぐ' }),
          ]),
          els.guestMessage,
          h('div', { class: 'button-row' }, [
            h('button', { type: 'button', class: 'small-button ghost', text: 'キャンセル', onclick: backToStart }),
          ]),
        ]),
        h('details', { class: 'p2p-manual' }, [
          h('summary', { text: 'うまくいかないとき: 接続文字列を直接やり取りする(このサーバを一切使いません)' }),
          h('h4', { class: 'section-title', text: '相手から受け取った文字列を貼り付ける' }),
          h('form', { onsubmit: guestSubmitOffer }, [
            els.offerInput,
            h('div', { class: 'button-row' }, [h('button', { type: 'submit', text: '接続情報を作る' })]),
            els.guestManualMessage,
          ]),
          els.answerOutputBlock,
        ]),
      ]);
      return els.guestBlock;
    }

    function build() {
      els = {};

      if (typeof RTCPeerConnection === 'undefined') {
        return h('div', { class: 'mode-body' }, [
          h('p', { class: 'form-message error', text: 'お使いのブラウザはWebRTCに対応していないため、この方式は利用できません。' }),
        ]);
      }

      els.roleChoice = h('div', {}, [
        h('p', { class: 'mode-lead', text: 'アカウント不要で、ブラウザ同士が直接つながって対局します。対局が始まったあとの着手は相手のブラウザへ直接届き、このサーバを通りません。' }),
        h('p', { class: 'note', text: 'つなぐときだけ、片方が出す短い「合図コード」をもう片方に渡す必要があります。近くにいるなら、そのコードを🔊音波で自動的に渡せます。' }),
        h('p', { class: 'note', text: REACH_NOTE }),
        h('div', { class: 'button-row' }, [
          h('button', { type: 'button', class: 'button', text: '対局を作成する(自分が黒/先手)', onclick: startHost }),
          h('button', { type: 'button', class: 'button secondary', text: '招待を受けて参加する(自分が白/後手)', onclick: startGuest }),
        ]),
      ]);

      els.setupBlock = h('div', {}, [els.roleChoice, buildHostBlock(), buildGuestBlock()]);

      els.players = h('p', { class: 'players-label' });
      els.score = h('p', { class: 'score-label' });
      els.boardContainer = h('div', { class: 'board-container' });
      els.status = h('p', { class: 'status-label', role: 'status' });
      els.resign = h('button', { type: 'button', class: 'small-button ghost', text: '投了する', onclick: resign });
      els.rematch = h('button', { type: 'button', class: 'small-button', text: 'もう一度対局する', hidden: true, onclick: requestRematch });

      els.boardSection = h('div', { hidden: true }, [
        h('div', { class: 'button-row' }, [
          h('button', { type: 'button', class: 'small-button ghost', text: '← 最初からやり直す', onclick: backToStart }),
        ]),
        els.players,
        els.score,
        els.boardContainer,
        els.status,
        h('div', { class: 'button-row' }, [els.resign, els.rematch]),
      ]);

      sonicAvailable = !!(window.PrevarsiSonic && window.PrevarsiAudioProfiles && window.PrevarsiSonic.isSupported());
      if (sonicAvailable) {
        hostProfile = window.PrevarsiAudioProfiles.buildSelect();
        els.hostProfileSlot.appendChild(hostProfile.wrap);
        guestProfile = window.PrevarsiAudioProfiles.buildSelect();
        els.guestProfileSlot.appendChild(guestProfile.wrap);

        // 受信中に方式を切り替えたら、新しい方式で聞き直す。Quiet.receiver()は
        // 生成時のprofileで固定されるため、作り直さないと「相手は新しい方式で
        // 送っているのにこちらは古い方式で聞き続ける」状態になり、画面には
        // 「ノイズを検出しています」しか出ず原因に気づけない。
        guestProfile.select.addEventListener('change', () => {
          if (guestListening) {
            stopSonicListen();
            toggleGuestListen();
          }
        });

        // マイクが物理的に無ければ、押しても必ず失敗するボタンを出さない。
        window.PrevarsiSonic.hasMicrophone().then((hasMic) => {
          if (hasMic) { return; }
          els.guestListen.hidden = true;
          els.guestProfileSlot.appendChild(unavailableNote('マイクが見つからないため、音波での受信は利用できません。合図コードを直接入力してください。'));
        });
      } else {
        els.hostPlay.hidden = true;
        els.guestListen.hidden = true;
        els.hostProfileSlot.appendChild(unavailableNote('お使いのブラウザは音波での送信に対応していません。合図コードを読み上げるなどして相手に伝えてください。'));
        els.guestProfileSlot.appendChild(unavailableNote('お使いのブラウザは音波での受信に対応していません。合図コードを直接入力してください。'));
      }

      window.addEventListener('beforeunload', () => {
        stopSonicSend();
        stopSonicListen();
        releaseSignal(true);
      });

      return h('div', { class: 'mode-body' }, [els.setupBlock, els.boardSection]);
    }

    function activate() {}

    /** 別のタブに移ったとき。スピーカー/マイクを握ったままにしない。 */
    function deactivate() {
      stopSonicSend();
      stopSonicListen();
    }

    return { build, activate, deactivate };
  })();

  // =====================================================================
  // コンピュータモード(一人用)。判定も次の一手の思考もサーバー側(?api=cpu_*)に
  // あり、ここは盤面の表示と着手の送信だけを担当する。
  // =====================================================================
  const CpuMode = (() => {
    let els = {};
    let boardView = null;
    let game = null;
    let busy = false;
    let loaded = false;
    const levelLabels = {}; // 強さの値 -> 表示名

    function showSetup() {
      els.boardSection.hidden = true;
      els.setup.hidden = false;
    }

    function showBoard() {
      els.setup.hidden = true;
      els.boardSection.hidden = false;
      if (boardView) { return; }
      boardView = createBoardView(els.boardContainer);
      boardView.onCellClick(submitMove);
    }

    function render(g) {
      game = g;
      const legal = (g.status === 'active' && g.my_turn) ? legalMoves(g.board, g.my_color) : [];
      // 印は「いちばん最後に置かれた石」(last_move)。ふつうはコンピュータの手だが、
      // コンピュータがパスして手番が戻ってきたときは自分の手がそれになる。
      boardView.render(g.board, legal, g.last_move);
      els.score.textContent = `黒 ${g.score.black} - 白 ${g.score.white}`;
      els.players.textContent =
        `あなた: ${colorName(g.my_color)} / ` +
        `コンピュータ: ${colorName(opponent(g.my_color))}(${levelLabels[String(g.level)] || ('レベル' + g.level)})`;

      let text;
      if (g.status === 'finished') {
        if (g.winner === 0) { text = '引き分けです。'; }
        else { text = (g.winner === g.my_color) ? 'あなたの勝ちです!' : 'コンピュータの勝ちです。'; }
        els.resign.hidden = true;
        els.rematch.hidden = false;
      } else {
        text = g.my_turn ? 'あなたの番です。石を置けるマスが光っています。' : 'コンピュータが考えています...';
        els.resign.hidden = false;
        els.rematch.hidden = true;
      }
      if (g.passed === 'cpu') { text = 'コンピュータは置ける場所が無いためパスになりました。' + text; }
      else if (g.passed === 'you') { text = 'あなたは置ける場所が無いためパスになりました。' + text; }
      els.status.textContent = text;
    }

    /** 通信に失敗して手元の表示が怪しくなったときに、サーバーの状態で描き直す。 */
    function resync(message) {
      els.status.textContent = message;
      api('cpu_state').then(res => {
        if (res.ok && res.game) { render(res.game); }
      }).catch(() => { /* 直せなければ表示はそのまま。再読み込みで復帰できる */ });
    }

    /** コンピュータが石を置いていたら、その手を知らせる音を鳴らす。 */
    function announceCpuMove(g) {
      if (normalizeMove(g.last_cpu) !== null) { playAlertSound(); }
    }

    function startGame(level, colorChoice) {
      if (busy) { return; }
      busy = true;
      els.setupMessage.className = 'form-message';
      els.setupMessage.textContent = '対局を準備しています...';
      // 「同じ設定でもう一局」では設定画面が隠れているので盤面側にも出しておく
      // (あなたが白のときはコンピュータの初手を待つぶんだけ間があく)。
      els.status.textContent = '対局を準備しています...';
      api('cpu_new', { level: level, my_color: colorChoice }, true).then(res => {
        busy = false;
        if (!res.ok) {
          els.setupMessage.className = 'form-message error';
          els.setupMessage.textContent = res.message || '対局を始められませんでした。';
          return;
        }
        els.setupMessage.textContent = '';
        showBoard();
        render(res.game);
        announceCpuMove(res.game);
      }).catch(() => {
        busy = false;
        els.setupMessage.className = 'form-message error';
        els.setupMessage.textContent = '通信に失敗しました。しばらくしてからもう一度お試しください。';
      });
    }

    function submitMove(r, c) {
      if (busy || !game || game.status !== 'active' || !game.my_turn) { return; }
      if (flipsForMove(game.board, game.my_color, r, c).length === 0) { return; }
      busy = true;
      playPlaceSound();

      // サーバーの返事を待たずに自分の石だけ先に置いて見せる。強いレベルでは
      // コンピュータが数秒考えるため、その間ずっと自分の着手が反映されないと
      // 押せていないように見えてしまう。正となる盤面は返事で上書きする。
      const optimistic = applyMove(game.board, game.my_color, r, c);
      if (optimistic !== null) {
        render(Object.assign({}, game, {
          board: optimistic,
          my_turn: false,
          passed: null,
          last_cpu: null,
          last_move: { row: r, col: c, color: game.my_color },
          score: countStones(optimistic),
        }));
      }

      api('cpu_move', { row: r, col: c }, true).then(res => {
        busy = false;
        if (!res.ok) {
          resync(res.message || '着手できませんでした。');
          return;
        }
        render(res.game);
        announceCpuMove(res.game);
      }).catch(() => {
        busy = false;
        resync('通信に失敗しました。');
      });
    }

    function resign() {
      if (!game || game.status !== 'active') { return; }
      if (!confirm('投了しますか?')) { return; }
      api('cpu_resign', {}, true).then(res => {
        if (res.ok) { render(res.game); }
      }).catch(() => { /* 失敗しても対局はそのまま続けられる */ });
    }

    function rematch() {
      if (!game) { return; }
      startGame(String(game.level), game.my_color === BLACK ? 'black' : 'white');
    }

    function build() {
      els = {};
      const levels = (bootstrap.cpu && bootstrap.cpu.levels) || [];
      levels.forEach((lv) => { levelLabels[String(lv.value)] = lv.label; });

      els.level = buildSelect({ id: 'cpu-level' }, levels, (bootstrap.cpu && bootstrap.cpu.defaultLevel) || 5);
      els.color = buildSelect({ id: 'cpu-color' }, [
        { value: 'black', label: '黒(先手)' },
        { value: 'white', label: '白(後手)' },
        { value: 'random', label: 'おまかせ' },
      ], 'black');
      els.setupMessage = h('p', { class: 'form-message', role: 'status' });

      els.setup = h('div', {}, [
        h('p', { class: 'mode-lead', text: 'コンピュータと一人で対局します。ログインも対戦相手も要りません。盤面の判定も次の一手の思考もすべてサーバー側で行うため、どの端末でも同じ強さで遊べます。' }),
        h('form', { class: 'invite-form', onsubmit: (e) => { e.preventDefault(); startGame(els.level.value, els.color.value); } }, [
          h('label', { for: 'cpu-level', text: 'コンピュータの強さ' }),
          els.level,
          h('label', { for: 'cpu-color', text: 'あなたの石' }),
          els.color,
          h('button', { type: 'submit', text: '対局を始める' }),
          els.setupMessage,
        ]),
        h('p', { class: 'note', text: '強いレベルほどコンピュータが長く考えます(最長でも1手あたり数秒で必ず打ちます)。' }),
      ]);

      els.players = h('p', { class: 'players-label' });
      els.score = h('p', { class: 'score-label' });
      els.boardContainer = h('div', { class: 'board-container' });
      els.status = h('p', { class: 'status-label', role: 'status' });
      els.resign = h('button', { type: 'button', class: 'small-button ghost', text: '投了する', onclick: resign });
      els.rematch = h('button', { type: 'button', class: 'small-button', text: '同じ設定でもう一局', hidden: true, onclick: rematch });

      els.boardSection = h('div', { hidden: true }, [
        h('div', { class: 'button-row' }, [
          h('button', { type: 'button', class: 'small-button ghost', text: '← 設定に戻る', onclick: showSetup }),
        ]),
        els.players,
        els.score,
        els.boardContainer,
        els.status,
        h('div', { class: 'button-row' }, [els.resign, els.rematch]),
      ]);

      return h('div', { class: 'mode-body' }, [els.setup, els.boardSection]);
    }

    /**
     * 対局はサーバーのセッションに残っているので、初めてこのタブを開いたとき
     * (再読み込み後や、JS無効版で始めた対局の続きを含む)だけ拾い直す。2回目
     * 以降の切り替えでは、その人が「設定に戻る」で戻した画面を勝手に上書きしない。
     */
    function activate() {
      if (loaded) { return; }
      loaded = true;
      api('cpu_state').then(res => {
        if (res.ok && res.game && res.game.status === 'active') {
          showBoard();
          render(res.game);
        }
      }).catch(() => { /* 拾えなければ設定画面のままでよい */ });
    }

    function deactivate() {}

    return { build, activate, deactivate };
  })();

  // =====================================================================
  // 設定タブ。音と表示のスイッチをここで組み立て、index.phpが出力している
  // 設定(アカウント・対戦結果の削除・プロダクト一覧)の要素をそのまま
  // このタブの中へ移動して再利用する(同じUIを2つ持たないため)。
  // =====================================================================
  const SettingsMode = (() => {
    /** アクセシブルな入り切りスイッチ。押すたびにgetter/setterを通して状態を切り替える。 */
    function toggleRow(labelText, description, getter, setter) {
      const state = h('span', { class: 'switch-state' });
      const button = h('button', { type: 'button', class: 'switch', role: 'switch' }, [
        h('span', { class: 'switch-track' }, [h('span', { class: 'switch-knob' })]),
        state,
      ]);

      function sync() {
        const on = getter();
        button.setAttribute('aria-checked', on ? 'true' : 'false');
        button.classList.toggle('on', on);
        state.textContent = on ? 'オン' : 'オフ';
      }
      button.addEventListener('click', () => { setter(!getter()); sync(); });
      Settings.onChange(sync);
      sync();

      return h('div', { class: 'settings-item' }, [
        h('div', { class: 'settings-item-head' }, [
          h('h3', { class: 'section-title', text: labelText }),
          button,
        ]),
        h('p', { class: 'note', text: description }),
      ]);
    }

    function build() {
      const rows = [];

      if (Settings.soundSupported()) {
        rows.push(toggleRow(
          '対局の音',
          '石を置いたときの音と、相手(コンピュータ含む)が石を置いた・招待が届いたときの知らせの音を鳴らします。',
          Settings.soundOn,
          Settings.setSoundOn
        ));
      } else {
        rows.push(h('div', { class: 'settings-item' }, [
          h('h3', { class: 'section-title', text: '対局の音' }),
          h('p', { class: 'note unavailable-note', text: 'お使いのブラウザでは音を鳴らせません。' }),
        ]));
      }

      rows.push(toggleRow(
        '直前に置かれた石の印',
        '相手がどこに置いたのかが分かるよう、いちばん最後に置かれた石に印をつけます。',
        Settings.showLastMove,
        Settings.setShowLastMove
      ));

      const body = h('div', { class: 'mode-body' }, rows);

      // index.phpが出している設定の塊をこのタブへ引き取る。JS無効環境用の
      // 「印」の切り替えフォームは、上のスイッチと役目が重なるので隠す。
      const serverSettings = document.getElementById('prevarsi-settings');
      if (serverSettings) {
        serverSettings.classList.remove('panel');
        serverSettings.classList.add('settings-embedded');
        const title = serverSettings.querySelector('.panel-title');
        if (title) { title.hidden = true; }
        const nojsMark = serverSettings.querySelector('#prevarsi-mark-setting-nojs');
        if (nojsMark) { nojsMark.hidden = true; }
        body.appendChild(serverSettings);
      }

      return body;
    }

    function activate() {}
    function deactivate() {}

    return { build, activate, deactivate };
  })();

  // =====================================================================
  // 起動: タブの組み立てと切り替え
  // =====================================================================
  document.addEventListener('DOMContentLoaded', () => {
    const app = document.getElementById('prevarsi-app');
    const nojs = document.getElementById('prevarsi-nojs');
    if (!app) { return; }

    const tabs = [
      { id: 'pmeikee', label: 'メイキー対戦', hint: 'ログインして相手を指定', mod: PMeikeeMode },
      { id: 'p2p', label: '直接つなぐ', hint: '音波の合図でP2P', mod: P2PMode },
      { id: 'cpu', label: 'コンピュータ', hint: '一人で遊ぶ', mod: CpuMode },
      { id: 'settings', label: '設定', hint: '音・表示', mod: SettingsMode },
    ];

    const tablist = h('div', { class: 'tabbar', role: 'tablist', 'aria-label': '対局方式' });
    const panels = h('div', { class: 'tabpanels' });

    tabs.forEach((tab) => {
      tab.button = h('button', {
        type: 'button',
        class: 'tab',
        role: 'tab',
        id: 'tab-' + tab.id,
        'aria-controls': 'panel-' + tab.id,
        'aria-selected': 'false',
        tabindex: '-1',
        onclick: () => switchTo(tab.id),
      }, [
        h('span', { class: 'tab-label', text: tab.label }),
        h('span', { class: 'tab-hint', text: tab.hint }),
      ]);
      tab.panel = h('section', {
        class: 'panel tabpanel',
        role: 'tabpanel',
        id: 'panel-' + tab.id,
        'aria-labelledby': 'tab-' + tab.id,
        hidden: true,
      }, [tab.mod.build()]);
      tablist.appendChild(tab.button);
      panels.appendChild(tab.panel);
    });

    function switchTo(id) {
      tabs.forEach((tab) => {
        const active = tab.id === id;
        tab.button.setAttribute('aria-selected', active ? 'true' : 'false');
        tab.button.tabIndex = active ? 0 : -1;
        tab.button.classList.toggle('active', active);
        tab.panel.hidden = !active;
        if (active) { tab.mod.activate(); } else { tab.mod.deactivate(); }
      });
      try { localStorage.setItem('prevarsi_mode', id); } catch (e) {}
    }

    // 左右キーでタブを移動できるようにする(WAI-ARIAのタブの作法)。
    tablist.addEventListener('keydown', (e) => {
      const index = tabs.findIndex((tab) => tab.button === document.activeElement);
      if (index < 0) { return; }
      let next = -1;
      if (e.key === 'ArrowRight') { next = (index + 1) % tabs.length; }
      else if (e.key === 'ArrowLeft') { next = (index - 1 + tabs.length) % tabs.length; }
      else if (e.key === 'Home') { next = 0; }
      else if (e.key === 'End') { next = tabs.length - 1; }
      if (next < 0) { return; }
      e.preventDefault();
      tabs[next].button.focus();
      switchTo(tabs[next].id);
    });

    app.appendChild(tablist);
    app.appendChild(panels);
    if (nojs) { nojs.hidden = true; }

    // JS無効版のリンクや共有URL(?view=...)から来た場合は、保存された前回のタブ
    // より「今このURLで開こうとしているもの」を優先する。
    const view = bootstrap.view || {};
    let initial = 'pmeikee';
    if (view.name === 'cpu') {
      initial = 'cpu';
    } else if (view.name === 'match') {
      initial = 'pmeikee';
    } else {
      try {
        const saved = localStorage.getItem('prevarsi_mode');
        if (saved && tabs.some((tab) => tab.id === saved)) { initial = saved; }
      } catch (e) { /* 読めなければ既定のタブでよい */ }
    }
    switchTo(initial);

    if (view.name === 'match' && view.matchId) {
      PMeikeeMode.openSharedMatch(view.matchId);
    }
  });
})();
