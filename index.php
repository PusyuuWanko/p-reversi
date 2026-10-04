<?php
/**
 * =====================================================================
 * プシューリバーシ (index.php) - 単一ファイル構成
 *
 * インターネット越しに対局できるオセロ(リバーシ)です。3つの対局方式が
 * あり、ブラウザ側(assets/game.js)がタブで切り替えます。
 *
 *   - PMeikeeモード: プシューメイキィ(アカウント基盤)にログインし、
 *     相手のユーザー名を指定して対局します。着手はこのサーバ自身の
 *     ストレージ(matches.jsonl)を経由してやり取りするため、双方が
 *     このページを開いていればリアルタイムに近い形で進みます。
 *   - P2Pモード: アカウント不要。ブラウザ同士がWebRTCで直接つながり、
 *     対局データもそのDataChannelを直接流れます(対局が始まったあとは
 *     このサーバを経由しません)。つなぐときの合図(offer/answer)だけを
 *     短い「合図コード」にしてこのサーバへ一時的に預け、そのコードを
 *     音波(quiet.js)で相手へ飛ばします。
 *   - コンピュータモード: 一人用。盤面の判定も次の一手の思考も
 *     このファイルの中(PHP)で行うため、JavaScriptが無くても遊べます。
 *
 * 他サービス(p-meikiee・p-memo等)と同じく、入口をこの1ファイルに
 * まとめる方針を踏襲しています。詳しくは
 * p-meikiee/ACCOUNTS_INTEGRATION_SPEC.md を参照してください。
 * =====================================================================
 */

declare(strict_types=1);

// 他のプロダクト(main 等)の住所の頭。台帳(pusyuu_registry)から決まります。
// 【ドメインを書き戻さないこと】以前はテンプレートに https://pusyuuwanko.com 等を固定で
// 書いていたため、IPやLAN、別のドメインから入った人の画面でも公開ドメインへ取りに
// 行っていました。$public は OGP のように、よそ(SNS)が取りに来るものだけ true。
// 台帳の係が無い・読めないときは空文字(=このページのホストの / から)に倒れます。
function prevarsiSiteBase(string $id, bool $public = false): string {
  return class_exists('PusyuuRegistryClient') ? PusyuuRegistryClient::baseHtml($id, $public) : '';
}

// =====================================================================
// 環境まわりの設定
//
// サーバーのハードやホスト名、置き場所は変わり得るので、環境によって
// 変わるものはコードの中に直接書かず、すべてここに集めてある。
// どれも「見つからない・繋がらない場合は機能が減るだけで、ページ自体は
// 動き続ける」ように扱うこと。
// =====================================================================

/**
 * 外の場所を指すものは、途中のフォルダ名まで含めて1件ずつここに書く。
 *
 * 「先頭のURLだけ定数にして 'scripts/notify-sound.js' を各所に直書き」と
 * いう分け方はしない。scripts や images というフォルダ名が今のままである
 * 保証は無く、それが変わったときに結局あちこち直すことになるため。
 * 逆に「変わる単位」は1件ずつのアドレスそのものなので、それをそのまま
 * 並べてある。1つだけ別の場所へ移した場合は、その行だけ書き換えればよい。
 *
 * BASE/DIR を挟んであるのは、丸ごと引っ越したときに1行で済ませるための
 * ただの短縮で、意味の単位はあくまで下の各エントリ。
 */
// 共有アセットは main(台帳のID)の中にあります。ここに書くのは「main の中のパス」だけで、
// 住所の頭(ドメイン)は台帳(pusyuu_registry)の main の顔から付けます(prevarsiSharedAsset)。
// 【ドメインをここに書き戻さないこと】以前は 'https://pusyuuwanko.com/pusyuusystem/' を
// 固定していたため、IPやLAN、別のドメインから入った人の画面でもわざわざ公開ドメインへ
// 取りに行き、そこへ届かない環境ではスクリプトも画像も出ませんでした。
const PREVARSI_SHARED_ASSET_BASE = '/pusyuusystem/';
const PREVARSI_SHARED_ASSET_REGISTRY_ID = 'main';

/** ブラウザから読み込む共有アセット(スクリプト・画像)の、main の中のパス。 */
const PREVARSI_SHARED_ASSETS = [
  'privacy_notice'   => PREVARSI_SHARED_ASSET_BASE . 'scripts/privacy_notice.js',
  'request_indicator'=> PREVARSI_SHARED_ASSET_BASE . 'scripts/request-indicator.js',
  'notify_sound'     => PREVARSI_SHARED_ASSET_BASE . 'scripts/notify-sound.js',
  'touch_tooltip'    => PREVARSI_SHARED_ASSET_BASE . 'scripts/touch-device-tool-tip.js',
  'favicon'          => PREVARSI_SHARED_ASSET_BASE . 'images/favicon.ico',
  'og_image'         => PREVARSI_SHARED_ASSET_BASE . 'images/NanimoyakunitatanaiPage.png',
  'default_avatar'   => PREVARSI_SHARED_ASSET_BASE . 'images/avater.jpg',
];

// 外のサービス(SNSのカード等)が取りに来るもの。今の入口ではなく公開の顔から組み立てます。
const PREVARSI_PUBLIC_ASSET_KEYS = ['og_image'];

/**
 * 共有ファイル(main/pusyuusystem/)のファイルシステム上の場所。
 *
 * 上へ辿って探す形にはしない。置き場所を変えたときに、無関係な「mainらしき
 * フォルダ」を掴んで読み込んでしまう危険があるため(2026-09-03に全サービスで
 * この方針に統一済み)。__DIR__ を使わないのは、PHPが入口スクリプト自身の
 * ディレクトリをカレントにする挙動に任せた方が、置き場所に対して素直なため。
 *
 * 見つからない場合は「その機能を使わない」に倒し、ページ自体は動かし続ける。
 */
const PREVARSI_SHARED_DIR = './../main/pusyuusystem/';

/** サーバー側で読み込む共有ファイルの場所。 */
const PREVARSI_SHARED_FILES = [
  'panic_handler'  => PREVARSI_SHARED_DIR . 'scripts/php_scripts/panic_handler.php',
  'usage_tracker'  => PREVARSI_SHARED_DIR . 'scripts/php_scripts/usage_tracker.php',
  'notation'       => PREVARSI_SHARED_DIR . 'scripts/php_scripts/notation.php',
  // 機械(クローラ・AIの取得)の判定も、このファイルの中(PusyuuSessionRobot)にあります。
  // 以前の bot_detect.php は、一覧とアルゴリズムを1枚にまとめるため中へ移りました。
  'session'        => PREVARSI_SHARED_DIR . 'scripts/php_scripts/pusyuu_session.php',
  'registry_client'=> PREVARSI_SHARED_DIR . 'scripts/php_scripts/pusyuu_registry_client.php',
];

/**
 * P2P対局(WebRTC)で使うSTUNサーバーのURL一覧。ブラウザのRTCPeerConnectionへ
 * そのまま渡せる形。複数並べれば順に試される。
 *
 * 【置き場所は台帳(pusyuu_registry)の p-reversi の設定 stun_urls です】空白区切りで
 * 並べます(例: "stun:stun.pusyuuwanko.com:3478")。ここにドメインを書き戻さないこと。
 *
 * どこに置いてあるかは推測せず、必ず明示する(その明示の場所が台帳に移っただけ)。
 * 「アクセスされているドメインから stun.<ドメイン> を組み立てる」書き方もできるが、
 * STUNを別のドメイン(無料ドメイン等)に置いた瞬間、実在しない名前を黙って
 * 引きに行くバグになるため採用していない。
 *
 * IPアドレスではなくホスト名で書くこと。回線側のアドレスは変わっても、
 * 名前で引いておけばDNS側の更新だけで追随できる。
 *
 * 台帳が読めない・設定が空のときはSTUNを使わない。その場合P2Pは同じネットワークの
 * 中にいる相手とだけつながる。名前が引けない・応答しない場合も同じ状態に落ちる
 * だけで、ページ自体は動き続ける。
 */
function prevarsiStunUrls(): array {
  $setting = class_exists('PusyuuRegistryClient') ? PusyuuRegistryClient::setting('p-reversi', 'stun_urls') : null;
  return ($setting === null) ? [] : (preg_split('/\s+/', trim($setting), -1, PREG_SPLIT_NO_EMPTY) ?: []);
}

/**
 * PREVARSI_SHARED_ASSETS のURLを引く。未登録のキー・台帳が読めないときは空文字
 * (そのスクリプトや画像が出ないだけで、ページ自体は動き続けます)。
 */
function prevarsiSharedAsset(string $key): string {
  $path = PREVARSI_SHARED_ASSETS[$key] ?? '';

  if ($path === '' || !class_exists('PusyuuRegistryClient')) {
    $url = '';
  } else if (in_array($key, PREVARSI_PUBLIC_ASSET_KEYS, true)) {
    $url = (string)PusyuuRegistryClient::publicUrl(PREVARSI_SHARED_ASSET_REGISTRY_ID, $path);
  } else {
    $url = (string)PusyuuRegistryClient::url(PREVARSI_SHARED_ASSET_REGISTRY_ID, $path);
  }

  return $url;
}

/** PREVARSI_SHARED_FILES のパスを引く。未登録のキーなら空文字。 */
function prevarsiSharedFile(string $key): string {
  return PREVARSI_SHARED_FILES[$key] ?? '';
}

// 共有スクリプト。どれも「あれば使う」で、無くてもページは動く
// (提供される関数の呼び出し側もfunction_existsで守ってある)。
foreach (['panic_handler', 'usage_tracker', 'notation', 'session', 'registry_client'] as $prevarsiSharedKey) {
  $prevarsiSharedPath = prevarsiSharedFile($prevarsiSharedKey);
  if ($prevarsiSharedPath !== '' && file_exists($prevarsiSharedPath)) { require_once($prevarsiSharedPath); }
}
if (function_exists('pusyuuRecordProductUsage')) {
  pusyuuRecordProductUsage('p-reversi');
}

// =====================================================================
// セッションの開始
//
// 【共有スクリプトの読み込みより後で開始すること】どちらの始め方を選ぶかは
// PusyuuSession が居るかどうかで決まるので、読み込む前には選べません。以前は
// ファイルの先頭(38行目)で session_start() していましたが、そこではまだ居ません。
// 【出力より前であること】Cookieの発行とクッキー無しの試験(リダイレクト)を行うため、
// 何かを echo した後では効きません。ここから下に出力はありません。
//
// 【$_SESSION はこれまでどおり使えます】共有スクリプト側が $_SESSION と自分の
// 保存先を同期します(PusyuuSession::startWithSuperglobal())。クッキーを切って
// いる相手にも、URLの引き換えコードでセッションが続きます。
// 【ただしPHPのセッション関数は効きません】session_regenerate_id() などは
// 「使われていないPHPのセッション」を相手にします。下の PrevarsiSession 経由で
// 呼んでください。
// =====================================================================
class PrevarsiSession {
  /** 共有スクリプトを通しているか。start() で1回だけ決まります。 */
  private static $viaLibrary = false;

  public static function start(): void {
    if (class_exists('PusyuuSession')) {
      self::$viaLibrary = true;
      PusyuuSession::startWithSuperglobal();
    } else {
      // 共有スクリプトが無い環境。以前の p-reversi とまったく同じ始め方です。
      self::$viaLibrary = false;
      session_cache_limiter('nocache');
      session_start();
    }
  }

  /**
   * セッションIDを作り直します(ログイン・ログアウト時の権限昇格対策)。
   * 【どちらの枝も同じ意味になるように揃えること】片方だけ直すと、
   * クッキー無しの利用者だけ固定化対策が外れます。
   */
  public static function regenerateId(): void {
    if (self::$viaLibrary) {
      PusyuuSession::regenerateId(true);
    } else {
      session_regenerate_id(true);
    }
  }
}

PrevarsiSession::start();

// ---------------------------------------------------------------------
// 対局データの暗号化ヘルパー。p-reversi自身が持つ、これが唯一の実装。
//
// 【共有版と行き来させないこと】以前はここで共有版(main/pusyuusystem/scripts/
// php_scripts/pusyuu_storage_crypto.php)をrequireし、無ければ
// if (!function_exists('pusyuuAesGcmEncrypt')) で同じ物を定義する、という
// 「あれば共有版・無ければ内蔵」の形にしていた。これは一番危険な形だった:
// 同じサービスが状況によって2つの実装を行き来するため、片方だけ直せば静かに
// 復号できなくなり、しかも「どちらが動いていたか」で再現性が変わる。
// 共有する利点も実は無かった——この形式で書いたファイルを読むのは書いた本人
// (=このp-reversi、鍵はp-reversi_key.php / p-reversi_signal_key.php)だけで、
// 他プロダクトは自分専用の鍵で自分のファイルを読み書きしているため、
// プロダクト間で形式を揃えても互いのファイルを読む場面が存在しない。
//
// 【変更してはいけないこと】保存形式は base64( IV 12バイト + TAG 16バイト + 暗号文 )。
// 変えると matches.jsonl / signals.jsonl の既存データが読めなくなる。
// ---------------------------------------------------------------------

function prevarsiAesGcmEncrypt(string $plaintext, string $rawKey): ?string {
  $iv = random_bytes(12);
  $tag = '';
  $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $rawKey, OPENSSL_RAW_DATA, $iv, $tag);

  if ($cipher === false) {
    $encoded = null;
  } else {
    // 【この並び順を変えないこと】IV 12バイト + TAG 16バイト + 暗号文。
    // 変えると matches.jsonl / signals.jsonl の既存データが読めなくなります。
    $encoded = base64_encode($iv . $tag . $cipher);
  }

  return $encoded;
}

function prevarsiAesGcmDecrypt(string $encoded, string $rawKey): ?string {
  $raw = base64_decode($encoded, true);

  // 28バイト未満は、IVとTAGだけでも足りない = そもそもこの形式ではありません。
  if ($raw === false || strlen($raw) < 28) {
    $plain = null;
  } else {
    $opened = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $rawKey, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    $plain = ($opened === false) ? null : $opened;
  }

  return $plain;
}

function prevarsiLoadOrCreateKey(string $keyFilePath): ?string {
  if (is_file($keyFilePath)) {
    $encoded = require $keyFilePath;
    $decoded = is_string($encoded) ? base64_decode($encoded, true) : false;
    // 長さが違う鍵をそのまま使うと、暗号化はできても復号だけ失敗します。ここで弾きます。
    $key = ($decoded !== false && strlen($decoded) === 32) ? $decoded : null;
  } else {
    $raw = random_bytes(32);

    if (@file_put_contents($keyFilePath, "<?php\nreturn '" . base64_encode($raw) . "';\n") === false) {
      $key = null;
    } else {
      @chmod($keyFilePath, 0600);
      $key = $raw;
    }
  }

  return $key;
}

function prevarsiReadEncryptedJsonlBlob(string $file, string $rawKey): array {
  $raw = file_exists($file) ? file_get_contents($file) : false;
  $plain = ($raw === false || trim($raw) === '')
    ? null
    : prevarsiAesGcmDecrypt(trim($raw), $rawKey);
  $rows = [];

  // 無い・読めない・空・復号できない、のどれでも「まだ1件も無い」と同じ扱いです。
  if ($plain !== null) {
    foreach (preg_split('/\r?\n/', $plain) as $line) {
      $line = trim($line);
      if ($line !== '') {
        $row = json_decode($line, true);
        if (is_array($row)) { $rows[] = $row; }
      }
    }
  }

  return $rows;
}

function prevarsiWriteEncryptedJsonlBlob(string $file, array $rows, string $rawKey): bool {
  $lines = [];
  foreach ($rows as $row) { $lines[] = json_encode($row, JSON_UNESCAPED_UNICODE); }

  $encrypted = prevarsiAesGcmEncrypt(implode("\n", $lines), $rawKey);
  $dir = dirname($file);

  if ($encrypted === null) {
    $ok = false;
  } else if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
    // mkdir が失敗しても、他のリクエストが同時に作っていれば is_dir は真になります。
    $ok = false;
  } else {
    // いったん別名で書いてから rename します。途中で落ちても、読み手が
    // 半分だけ書かれたファイルを掴むことがありません(rename は不可分)。
    $tmp = $dir . '/.' . basename($file) . '.' . bin2hex(random_bytes(6)) . '.tmp';

    if (@file_put_contents($tmp, $encrypted) === false) {
      $ok = false;
    } else if (!@rename($tmp, $file)) {
      @unlink($tmp);
      $ok = false;
    } else {
      $ok = true;
    }
  }

  return $ok;
}

// =====================================================================
// アカウント機能の実体は p-reversi の中にはありません。共有スクリプトが提供する
// pusyuuAccount〜() へ頼み、p-reversi はその答えを自分の言葉へ翻訳するだけです。
//
// 以前はここに、メイキィと話すクラスの全文(500行あまり)が「p-memo/pipsと同じ実装を
// そのままコピー」という形で埋め込まれていました。その運用は実際には揃わず、
// toolboxの複製だけが1文字違っていたせいでtoolboxのアカウント機能が全滅して
// いました(2026-09-06)。持ち物が1つなら、そもそも食い違いようがありません。
//
// 【使うときは必ず PrevarsiAccount を通すこと】
// 本文から直接 pusyuuAccount〜() を呼ばないでください。共有スクリプトが無い環境では
// それらの関数自体が存在せず、「Call to undefined function」で致命的エラーになり、
// p-reversiのページが1行も表示されなくなります。対局中でも同じです。
//
// 読み込みは他の共有ファイル(PREVARSI_SHARED_FILES)と同じ考え方で、
// 見つからなければその機能を使わないだけにします。
// =====================================================================
$directry = PREVARSI_SHARED_DIR . 'scripts/php_scripts/meikiee_client.php';
if (file_exists($directry)) {
  require_once($directry);
}

// ================================================================
// p-reversi から見たアカウント機能(p-reversiの「顔」)
//
// p-reversiはログインしなくても対局できます。ここが返す「使えないときの答え」は、
// そのログイン不要の側へ素直に倒れるための形です。
// ================================================================
class PrevarsiAccount {
  /** アカウント機能が今使えるかどうか。他のメソッドは全部これを先に見ます。 */
  public static function ready(): bool {
    if (function_exists('pusyuuAccountReady')) {
      $result = pusyuuAccountReady();
    } else {
      // 共通のアカウント連携ファイルが置かれていないサーバ。単体で動かすための道です。
      $result = false;
    }

    return $result;
  }

  /** 使えないときの理由(利用者にそのまま見せてよい1文)。 */
  public static function unavailableReason(): string {
    if (function_exists('pusyuuAccountUnavailableReason')) {
      $result = pusyuuAccountUnavailableReason();
    } else {
      $result = 'このサーバにはアカウント連携機能が設置されていないため、メイキィでのログインはご利用いただけません。対局は通常どおりお楽しみいただけます。';
    }

    return $result;
  }

  /** 直近のログインが失敗していれば、その理由を1度だけ返します(読んだら消えます)。 */
  public static function takeSignInError(): string {
    if (function_exists('pusyuuAccountTakeSignInError')) {
      $result = pusyuuAccountTakeSignInError();
    } else {
      $result = '';
    }

    return $result;
  }

  /**
   * 「使えるなら $work を実行し、使えないなら $fallback を返す」。
   * 判定をここ1箇所に集めておけば、メソッドを足すときに書き忘れようがありません。
   * $fallback は引数なので先に評価されます。軽いものだけを渡してください。
   */
  private static function whenReady(callable $work, $fallback) {
    if (self::ready()) {
      $result = $work();
    } else {
      $result = $fallback;
    }

    return $result;
  }

  /** p-reversi自身のセッションが持っているトークン。未ログインなら空文字。 */
  public static function token(): string {
    return prevarsiAccountsToken();
  }

  /**
   * トークンの持ち主。['ok'=>bool,'user'=>array,'rejected'=>bool,'message'=>string]。
   *
   * 【rejected を必ず見てください】「ok以外なら全部ログアウト」と書くと、メイキィが
   * 一時的に落ちただけで対局中の利用者がログアウト扱いになります。捨ててよいのは
   * 「そのトークンはもう無効だ」とメイキィが答えたときだけです。
   */
  public static function sessionResult(): array {
    $token = self::token();

    if ($token === '') {
      // 未ログイン。rejected を false にしておくのが要点です。true にすると
      // 呼び出し側が「無効なトークンだった」と解釈してセッションを破棄します。
      $result = ['ok' => false, 'rejected' => false, 'message' => ''];
    } else {
      $result = self::whenReady(static function () use ($token) {
        return pusyuuAccountSelf($token);
      }, ['ok' => false, 'rejected' => false, 'message' => self::unavailableReason()]);
    }

    return $result;
  }

  /** ログイン画面へのリンク。使えないときは空文字(リンクを出さないでください)。 */
  public static function loginUrl(): string {
    return self::whenReady(static function () {
      return pusyuuAccountSignInUrl();
    }, '');
  }

  /** アカウント作成画面へのリンク。 */
  public static function createUrl(): string {
    return self::whenReady(static function () {
      return pusyuuAccountCreateUrl();
    }, '');
  }

  /** アカウント編集画面へのリンク。 */
  public static function manageUrl(): string {
    return self::whenReady(static function () {
      return pusyuuAccountManageUrl();
    }, '');
  }

  /** ログアウトの戻り先URL。使えないときは自分のトップへ戻します。 */
  public static function logoutUrl(): string {
    return self::whenReady(static function () {
      return pusyuuAccountSignOutUrl();
    }, './');
  }

  /** 手元のトークンを失効させます(画面遷移は伴いません)。 */
  public static function revokeToken(string $token): void {
    if (self::ready()) {
      pusyuuAccountRevokeToken($token);
    }
  }

  /**
   * 直近の失敗が「メイキィまで届かなかった」ものかどうか。
   * profile() などが null を返したとき、「居ない」のか「聞けなかった」のかを
   * 見分けるために使います。混ぜると、実在する相手を「居ない」と断定します。
   */
  public static function lastFailureWasConnection(): bool {
    if (function_exists('pusyuuAccountLastFailureWasConnection')) {
      $wasConnection = pusyuuAccountLastFailureWasConnection();
    } else {
      // 基盤そのものが無いなら「届かなかった」側に倒します。
      // 「居ない」と断定してしまうと、生きている相手の記録を消す側へ倒れます。
      $wasConnection = true;
    }

    return $wasConnection;
  }

  /** ユーザー名から公開プロフィールを引きます。見つからなければ null。 */
  public static function profile(string $username): ?array {
    return self::whenReady(static function () use ($username) {
      return pusyuuAccountProfile($username);
    }, null);
  }

  /**
   * その保存先IDのアカウントがまだ存在するか。
   * 確認できなかったときは true を返します。確認できないことを理由に、
   * 生きている相手との対局記録を消してしまう方が有害だからです。
   */
  public static function accountExists(string $storageId): bool {
    return self::whenReady(static function () use ($storageId) {
      return pusyuuAccountExists($storageId);
    }, true);
  }
}

// =====================================================================
// P2P対局(WebRTC)のICEサーバー設定。
//
// 別々のネットワークにいる二人をつなぐには、自分がインターネット側から
// どう見えているかを教えてくれるSTUNサーバーが要る。以前はGoogleの公開
// STUN(stun.l.google.com)を借りていたが、自社以外のサーバーに頼らない方針に
// したため、自前のSTUN(coturn)を指すようにしてある。
//
// どのSTUNを使うかは prevarsiStunUrls()(台帳の設定)の通りで、ここでは
// 推測も加工もしない。STUNが引けない・応答しない場合でもページは動き、
// P2Pが「同じネットワークの中にいる相手とだけつながる」状態に落ちるだけ。
// =====================================================================

/** ブラウザのRTCPeerConnectionへそのまま渡せる形のICEサーバー一覧。 */
function prevarsiIceServers(): array {
  $urls = array_values(array_filter(prevarsiStunUrls(), static fn($url) => is_string($url) && $url !== ''));

  if ($urls === []) {
    // STUNが1つも無くてもページは動きます(同じネットワーク内の相手とだけ繋がる状態)。
    $servers = [];
  } else {
    $servers = [['urls' => $urls]];
  }

  return $servers;
}

// =====================================================================
// プシューリバーシ自身のログイン状態
// =====================================================================

function prevarsiAccountsToken(): string {
  return isset($_SESSION['pusyuu_accounts_token']) ? (string)$_SESSION['pusyuu_accounts_token'] : '';
}

/** ログイン中ならユーザー情報(userid/username/name等)、そうでなければnull。1リクエスト中は1回だけ問い合わせる。 */
function prevarsiCurrentUser(): ?array {
  static $checked = false;
  static $user = null;

  // 2回目以降は $checked が true なので、この塊ごと素通りして控えをそのまま返します。
  if ($checked === false) {
    $checked = true;
    $token = prevarsiAccountsToken();

    if ($token === '') {
      $user = null;
    } else {
      $res = PrevarsiAccount::sessionResult();

      if (!empty($res['ok'])) {
        $user = $res['user'];
      } else {
        // 【失敗しても、いつでも控えを捨ててよいわけではありません】
        // 以前ここは「ok以外なら問答無用でトークンを消す」と書かれていました。その結果、
        // メイキィが一時的に落ちているだけで対局中の利用者がログアウト扱いになり、
        // 復旧しても未ログインのまま、という状態になります。捨ててよいのは「そのトークンは
        // もう無効だ」とメイキィが答えたときだけです。届かなかっただけのときは何もしません。
        if (!empty($res['rejected'])) {
          unset($_SESSION['pusyuu_accounts_token']);
        }
        $user = null;
      }
    }
  }

  return $user;
}

function prevarsiLogout(): void {
  $token = prevarsiAccountsToken();
  if ($token !== '') { PrevarsiAccount::revokeToken($token); }
  unset($_SESSION['pusyuu_accounts_token']);
  PrevarsiSession::regenerateId();
}

// ---------------------------------------------------------------------
// CSRFトークン(p-memoの pmemo_token と同じ考え方)
// ---------------------------------------------------------------------

function prevarsiCsrfToken(): string {
  if (empty($_SESSION['prevarsi_csrf'])) {
    $_SESSION['prevarsi_csrf'] = bin2hex(random_bytes(32));
  }

  return (string)$_SESSION['prevarsi_csrf'];
}

function prevarsiCsrfIsValid(): bool {
  $sent = $_POST['csrf'] ?? null;
  // is_string を先に見るのは、配列で送られてきたときに hash_equals が
  // TypeError で落ちるのを防ぐためです。
  $valid = is_string($sent)
    && !empty($_SESSION['prevarsi_csrf'])
    && hash_equals((string)$_SESSION['prevarsi_csrf'], $sent);

  return $valid;
}

// =====================================================================
// オセロ判定エンジン(サーバ権威版。PMeikeeモードの着手はすべてここを通す)
// =====================================================================

const O_EMPTY = 0;
const O_BLACK = 1;
const O_WHITE = 2;
const O_DIRS = [[-1,-1],[-1,0],[-1,1],[0,-1],[0,1],[1,-1],[1,0],[1,1]];

function othelloInitialBoard(): array {
  $b = array_fill(0, 8, array_fill(0, 8, O_EMPTY));
  $b[3][3] = O_WHITE; $b[3][4] = O_BLACK; $b[4][3] = O_BLACK; $b[4][4] = O_WHITE;

  return $b;
}

function othelloOpponent(int $player): int { return $player === O_BLACK ? O_WHITE : O_BLACK; }
function othelloInBounds(int $r, int $c): bool { return $r >= 0 && $r < 8 && $c >= 0 && $c < 8; }

function othelloFlips(array $board, int $player, int $row, int $col): array {
  $flips = [];

  // 盤の外、または既に石がある場所には置けません(=ひっくり返る石も無い)。
  if (othelloInBounds($row, $col) && $board[$row][$col] === O_EMPTY) {
    $opp = othelloOpponent($player);

    foreach (O_DIRS as [$dr, $dc]) {
      $line = [];
      $r = $row + $dr; $c = $col + $dc;
      while (othelloInBounds($r, $c) && $board[$r][$c] === $opp) { $line[] = [$r, $c]; $r += $dr; $c += $dc; }

      // 相手の石が続いた先が自分の石なら、その間を挟んだことになります。
      if (!empty($line) && othelloInBounds($r, $c) && $board[$r][$c] === $player) {
        $flips = array_merge($flips, $line);
      }
    }
  }

  return $flips;
}

function othelloLegalMoves(array $board, int $player): array {
  $moves = [];

  for ($r = 0; $r < 8; $r++) {
    for ($c = 0; $c < 8; $c++) {
      if (!empty(othelloFlips($board, $player, $r, $c))) { $moves[] = [$r, $c]; }
    }
  }

  return $moves;
}

function othelloApplyMove(array $board, int $player, int $row, int $col): ?array {
  $flips = othelloFlips($board, $player, $row, $col);

  // 1枚もひっくり返らない手は反則。null を返して呼び出し側に弾かせます。
  if (empty($flips)) {
    $next = null;
  } else {
    $board[$row][$col] = $player;
    foreach ($flips as [$r, $c]) { $board[$r][$c] = $player; }
    $next = $board;
  }

  return $next;
}

function othelloScore(array $board): array {
  $black = 0; $white = 0;

  foreach ($board as $row) {
    foreach ($row as $cell) {
      if ($cell === O_BLACK) { $black++; } else if ($cell === O_WHITE) { $white++; }
    }
  }

  return ['black' => $black, 'white' => $white];
}

/**
 * 着手後の手番進行。相手に合法手が無ければ自動パスし、双方とも合法手が
 * 無ければ対局終了とする(assets/game.js の advanceTurn と同じ規則)。
 * 戻り値: ['turn'=>int|null, 'game_over'=>bool]
 */
function othelloAdvanceTurn(array $board, int $movedPlayer): array {
  $opp = othelloOpponent($movedPlayer);

  if (!empty(othelloLegalMoves($board, $opp))) {
    $next = ['turn' => $opp, 'game_over' => false];
  } else if (!empty(othelloLegalMoves($board, $movedPlayer))) {
    // 相手に打つ手が無いので自動パス。手番は自分のまま戻ります。
    $next = ['turn' => $movedPlayer, 'game_over' => false];
  } else {
    // 双方とも打てない = 対局終了。
    $next = ['turn' => null, 'game_over' => true];
  }

  return $next;
}

// =====================================================================
// 対局データの保存(自分自身のストレージ。assets/<ランダム名>/matches.jsonl)
//
// PMeikeeのuserDataは小さな付随データ専用のため、対局の盤面のような
// まとまったデータはここに持つ(ACCOUNTS_INTEGRATION_SPEC.md 4.1節参照)。
// 対局者名・盤面が平文で載るため、AES-256-GCMで暗号化して保存する
// (鍵は.pusyuuHiddenFiles/p-reversi_key.phpに自己生成)。ディレクトリ名も
// .htaccessに依存せず推測不能なランダム名にして隠蔽している。
// 置き場所はassets/配下だが、中身は上記のとおり暗号化済みで、かつ名前を
// 知らなければURLに辿り着けない。移動する場合はMATCHES_FILEと
// SIGNALS_FILEの両方を必ず同じディレクトリに揃えること
// (日次メンテナンスの状態ファイルはdirname(MATCHES_FILE)に付いて回る)。
// =====================================================================

const MATCHES_FILE = './assets/3362e9bd4f424e988782abc42c863886/matches.jsonl';

/** .pusyuuHiddenFiles ディレクトリそのものの場所。既存の共有鍵ファイルを手がかりに探す。 */
function prevarsiHiddenFilesDir(): ?string {
  static $resolved = false;
  static $dir = null;

  // 2回目以降は $resolved が true なので、この塊ごと素通りして答えを使い回します。
  if ($resolved === false) {
    $resolved = true;

  // 【アカウント機能の定数を借りないこと】
  // 以前ここは PUSYUU_ACCOUNTS_KEY_FILE_CANDIDATES を使っていました。しかしそれは
  // アカウント連携の共有スクリプトが定義する定数で、そちらが無い環境では**未定義**です。
  // 借りたままだと、共有スクリプトを外しただけで p-reversi の対局データの鍵が引けなくなり、
  // 対局そのものが動かなくなります。ここが探しているのは p-reversi 自身の鍵置き場なので、
  // アカウント機能の有無とは関係なく自分で持ちます。
  //
  // 手がかりのファイル名が共有鍵と同じなのは、.pusyuuHiddenFiles を見つけるための
  // 目印として使っているだけで、中身は読みません。
    $markers = ['.pusyuuHiddenFiles/pips_account_key.php', '.pusyuuHiddenFiles/p-reversi_key.php'];
    $cur = '.';

    // 上限8段は、見つからないときに延々と登り続けないための歯止めです。
    for ($i = 0; $i <= 8 && $dir === null; $i++) {
      foreach ($markers as $relative) {
        $candidate = $cur . '/' . $relative;
        if (file_exists($candidate)) {
          $dir = dirname($candidate);
          break;
        }
      }
      $cur = $cur . '/..';
    }

    if ($dir === null) {
      // 黙って null を返すと、対局が保存できない理由が誰にも分かりません。
      error_log('[p-revarsi] .pusyuuHiddenFiles が見つかりません。');
    }
  }

  return $dir;
}

function prevarsiMatchesKey(): ?string {
  $hidden = prevarsiHiddenFilesDir();

  if ($hidden === null) {
    $key = null;
  } else {
    $key = prevarsiLoadOrCreateKey($hidden . '/p-reversi_key.php');
  }

  return $key;
}

/**
 * matches.jsonlをAES-256-GCMで暗号化して読み書きする。復号に失敗した場合
 * (暗号化前の旧データ、または鍵未取得)は旧形式の平文JSONLにフォールバックする。
 * 書き込みは鍵が取得できる限り常に暗号化して保存するため、一度でも書き込みが
 * 発生した時点で自然に暗号化データへ移行する。
 */
function prevarsiReadMatches(): array {
  $key = prevarsiMatchesKey();
  $raw = ($key === null) ? '' : (file_exists(MATCHES_FILE) ? trim((string)file_get_contents(MATCHES_FILE)) : '');
  $plain = ($key === null || $raw === '') ? null : prevarsiAesGcmDecrypt($raw, $key);

  if ($key !== null && $raw === '') {
    // 鍵はあるがファイルが空。まだ1件も無い、で確定です。
    $rows = [];
  } else if ($plain !== null) {
    $rows = [];
    foreach (preg_split('/\r?\n/', $plain) as $line) {
      $line = trim($line);
      if ($line !== '') {
        $row = json_decode($line, true);
        if (is_array($row)) { $rows[] = $row; }
      }
    }
  } else {
    // 鍵が無い、または復号できなかった場合。暗号化前の旧データの可能性があるので、
    // 平文として読み直します。ここで諦めると、移行前の対局が見えなくなります。
    $rows = prevarsiReadJsonl(MATCHES_FILE);
  }

  return $rows;
}

function prevarsiWriteMatches(array $rows): bool {
  $key = prevarsiMatchesKey();

  if ($key === null) {
    $ok = prevarsiWriteJsonl(MATCHES_FILE, $rows);
  } else {
    // 鍵が取れる限り常に暗号化して保存します。一度でも書き込みが起きた時点で
    // このファイルは自然に暗号化データへ移行します。
    $ok = prevarsiWriteEncryptedJsonlBlob(MATCHES_FILE, $rows, $key);
  }

  return $ok;
}

function prevarsiReadJsonl(string $file): array {
  $raw = file_exists($file) ? file_get_contents($file) : false;
  $rows = [];

  // 無い・読めない・空、のどれでも「まだ1件も無い」と同じ扱いにします。
  if ($raw !== false && trim($raw) !== '') {
    foreach (preg_split('/\r?\n/', $raw) as $line) {
      $line = trim($line);
      if ($line !== '') {
        $row = json_decode($line, true);
        if (is_array($row)) { $rows[] = $row; }
      }
    }
  }

  return $rows;
}

function prevarsiWriteJsonl(string $file, array $rows): bool {
  $lines = [];
  foreach ($rows as $row) { $lines[] = json_encode($row, JSON_UNESCAPED_UNICODE); }

  $dir = dirname($file);
  // mkdir が失敗しても、他のリクエストが同時に作っていれば is_dir は真になります。
  $noDir = (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir));
  $tmp = $dir . '/.' . uniqid('m_', true) . '.tmp';
  $fp = $noDir ? false : fopen($tmp, 'w');

  if ($fp === false) {
    $ok = false;
  } else {
    flock($fp, LOCK_EX);
    fwrite($fp, implode("\n", $lines));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    // いったん別名で書いてから rename します。途中で落ちても、読み手が
    // 半分だけ書かれたファイルを掴むことがありません(rename は不可分)。
    if (!rename($tmp, $file)) {
      @unlink($tmp);
      $ok = false;
    } else {
      $ok = true;
    }
  }

  return $ok;
}

/**
 * ロックを取ってから読み込み→変更→(必要なら)書き込みを行う汎用版。
 * $mutator は array&$rows を受け取り ['save'=>bool, 'result'=>mixed] を返す。
 * 戻り値: ['status'=>true|false|null, 'result'=>mixed] (statusのnullは保存エラー)
 *
 * 対局データ(matches.jsonl)とP2Pの合図データ(signals.jsonl)で手順が全く同じ
 * なので、対象ファイルと読み書き関数だけを引数で受け取る形にしてある。
 */
function prevarsiTransactOn(string $file, callable $reader, callable $writer, callable $mutator): array {
  $lockFile = $file . '.lock';
  $dir = dirname($lockFile);
  // mkdir が失敗しても、他のリクエストが同時に作っていれば is_dir は真になります。
  $noDir = (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir));
  $lockHandle = $noDir ? false : fopen($lockFile, 'c');

  if ($lockHandle === false) {
    // 鍵をかけられないまま読み書きすると、同時に来た2つの更新が互いを消します。
    // 掛けられないなら何もしないのが正解です。
    $outcomeArr = ['status' => null, 'result' => null];
  } else {
    flock($lockHandle, LOCK_EX);
    $rows = $reader();
    $outcome = $mutator($rows);
    $status = false;

    // status は3値です。false=保存不要、true=保存できた、null=保存に失敗した。
    if (!empty($outcome['save'])) {
      $status = $writer($rows) ? true : null;
    }

    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    $outcomeArr = ['status' => $status, 'result' => $outcome['result'] ?? null];
  }

  return $outcomeArr;
}

/** 対局データ(matches.jsonl)に対するprevarsiTransactOn()。 */
function prevarsiTransact(callable $mutator): array {
  $outcome = prevarsiTransactOn(MATCHES_FILE, 'prevarsiReadMatches', 'prevarsiWriteMatches', $mutator);

  return $outcome;
}

function prevarsiFindMatchIndex(array $matches, string $id): ?int {
  $found = null;

  foreach ($matches as $i => $m) {
    if (($m['id'] ?? '') === $id) {
      $found = $i;
      break;
    }
  }

  return $found;
}

/**
 * 自分が関わっている「終了済み」の対局記録だけを削除する(設定画面の
 * 「対戦結果の削除」用)。進行中/招待中の対局は誤って消さないよう対象外にする。
 * 戻り値: 削除できた場合は削除件数、保存に失敗した場合はnull。
 */
function prevarsiClearFinishedMatches(string $myId): ?int {
  $tx = prevarsiTransact(static function (array &$rows) use ($myId): array {
    $before = count($rows);
    $rows = array_values(array_filter($rows, static function ($m) use ($myId) {
      $mine = (($m['black']['userid'] ?? null) === $myId) || (($m['white']['userid'] ?? null) === $myId);
      $finished = ($m['status'] ?? '') === 'finished';
      return !($mine && $finished);
    }));
    return ['save' => true, 'result' => $before - count($rows)];
  });
  return $tx['status'] === true ? $tx['result'] : null;
}

// =====================================================================
// p-drive連携: 「相手のアカウントがまだ存在するか」の確認。
//
// p-chatと同じ設計方針(P_DRIVE_INTEGRATION_SPEC.md 7節)。対局の実データは
// 引き続きこのファイル自身のローカルストレージ(matches.jsonl)で管理し、
// 相手アカウントの生死はp-meikieeのアカウントAPI`account_exists`
// (ACCOUNTS_INTEGRATION_SPEC.md参照)を直接呼んで確認する。p-driveやp-meikiee
// 側に何かを書き込む必要はない。p-reversiには匿名の概念が無い(対局は常に
// 実アカウント同士)ため、p-chatにあった匿名の除外処理は不要。
// =====================================================================

/** 指定したstorage_idが実在するアカウントかどうかをp-meikieeへ直接確認する。 */
function prevarsiAccountExists(string $storageId): bool {
  // 【戻り値はboolです。配列として触らないこと】
  // 以前ここは $res['ok'] / $res['exists'] と配列で受けていました。顔の側がboolを
  // 返すようになったあともその形が残っていると、boolへの添字アクセスは常にnullなので
  // **この関数がいつでもfalseを返し**、生きている相手を「退会済み」と誤判定します。
  // p-chatでは同じ間違いで、生きている部屋とメッセージが削除されていました。
  //
  // なお、確認できなかったときにfalseを返す設計自体が危険です。メイキィが一瞬
  // 落ちただけで巻き添えが出ます。顔の側は確認できない場合trueを返します。
  return PrevarsiAccount::accountExists($storageId);
}

/**
 * 対局を有効化する前に確認する。招待後、承諾される前に招待者(black)が
 * アカウントを削除しているかもしれないため、有効化直前に念のため確認する
 * (承諾者(white)自身は今まさにこのリクエストをしているので存在確認は不要)。
 */
function prevarsiCanActivateMatch(string $blackId): bool {
  return prevarsiAccountExists($blackId);
}

/** 対局を丸ごと削除する内部ヘルパー。アクセス確認は呼び出し元の責務。 */
function prevarsiDeleteMatchInternal(string $matchId): bool {
  $tx = prevarsiTransact(static function (array &$rows) use ($matchId): array {
    $idx = prevarsiFindMatchIndex($rows, $matchId);
    if ($idx === null) { return ['save' => false, 'result' => false]; }
    array_splice($rows, $idx, 1);
    return ['save' => true, 'result' => true];
  });
  return $tx['status'] === true;
}

/** 監査ログ(prevarsiSweepStaleMatches()専用)への追記先。恒久的な記録のため削除・無効化スイッチは持たない。 */
function prevarsiOrphanSweepLogFile(): string {
  $path = dirname(MATCHES_FILE) . '/stale_match_sweep_log.jsonl';

  return $path;
}

function prevarsiOrphanSweepLog(array $record): void {
  $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  // 組み立てられなかったときは何も書きません(壊れた行を残す方が困るため)。
  if ($line !== false) {
    @file_put_contents(prevarsiOrphanSweepLogFile(), $line . "\n", FILE_APPEND | LOCK_EX);
  }
}

/**
 * 「誰かがその対局を実際に開いたとき」だけ働く受動的な確認を補う能動版。
 * 全対局のうちpending(承諾前の招待)・active(進行中)を巡回し、black/white
 * 双方のアカウントがまだ実在するか(`account_exists`)を確認する。片方でも
 * 既に削除されていれば、誰も開かなくてもここで削除する。finished(終了済み)は
 * 対象外(本人がprevarsiClearFinishedMatches()で明示的に消す既存の仕様に
 * 委ねる。対局記録として残す価値があるため、相手の退会だけを理由に自動では
 * 消さない)。
 *
 * 呼び出しはprevarsiRunDailyMaintenanceIfNeeded()経由、1日1回のみを想定する
 * (全対局数ぶんp-meikieeへの問い合わせが発生するコストがあるため)。
 */
function prevarsiSweepStaleMatches(): array {
  $removed = [];

  foreach (prevarsiReadMatches() as $match) {
    $matchId = (string)($match['id'] ?? '');
    $blackId = (string)($match['black']['userid'] ?? '');
    $whiteId = (string)($match['white']['userid'] ?? '');

    // finished(終了済み)は対象外です。対局記録として残す価値があるので、
    // 相手の退会だけを理由に自動では消しません。
    $target = ($matchId !== '')
      && in_array($match['status'] ?? '', ['pending', 'active'], true)
      && ($blackId !== '') && ($whiteId !== '');

    if ($target) {
      $blackOk = prevarsiAccountExists($blackId);
      $whiteOk = prevarsiAccountExists($whiteId);

      // 【両方生きているときだけ残す、ではありません】確認できなかった場合も
      // prevarsiAccountExists() は true を返します。生きている相手の対局を
      // 「確認できなかった」を理由に消さないための安全側です。
      if (!$blackOk || !$whiteOk) {
        prevarsiOrphanSweepLog(['ts' => date('c'), 'match_id' => $matchId, 'black' => $blackId, 'white' => $whiteId, 'black_exists' => $blackOk, 'white_exists' => $whiteOk]);
        if (prevarsiDeleteMatchInternal($matchId)) {
          $removed[] = $matchId;
        }
      }
    }
  }

  return ['ok' => true, 'removed' => $removed];
}

/** prevarsiRunDailyMaintenanceIfNeeded()が最終実行日を記録する先。JSON1件だけの軽量なファイル。 */
function prevarsiDailyMaintenanceStateFile(): string {
  $path = dirname(MATCHES_FILE) . '/daily_maintenance_state.json';

  return $path;
}

/**
 * prevarsiSweepStaleMatches()を専用cronを組まずに1日1回だけ自動的に回す
 * (p-meikiee/p-chatと同じ発想・同じ実装パターン)。「最終実行日」をJSON1件に
 * 記録し、通常のリクエストのたびに今日の日付と比較、変わっていた場合だけ
 * その場で実行する。日付が変わった瞬間に複数リクエストが同時に来ても二重
 * 実行しないよう、実行前に排他ロックを取り、ロック取得後にもう一度日付を
 * 確認する。ロックが取れなければ(既に他のリクエストが実行中)待たずに諦める。
 */
function prevarsiRunDailyMaintenanceIfNeeded(): void {
  $file = prevarsiDailyMaintenanceStateFile();
  $today = date('Y-m-d');
  $dir = dirname($file);

  // 1回目の確認。ほとんどの要求はここで素通りします(ロックも取りません)。
  $raw = @file_get_contents($file);
  $state = $raw !== false ? json_decode($raw, true) : null;
  $doneToday = is_array($state) && ($state['last_run'] ?? '') === $today;

  // mkdir が失敗しても、他のリクエストが同時に作っていれば is_dir は真になります。
  $noDir = (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir));
  $fp = ($doneToday || $noDir) ? false : @fopen($file . '.lock', 'c');

  if ($fp !== false) {
    // ロックが取れなければ、既に他の要求が実行中。待たずに諦めます。
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
      fclose($fp);
    } else {
      // 【ロックを取った後にもう一度確かめること】日付が変わった瞬間に複数の
      // 要求が同時に来ると、1回目の確認は全員素通りします。ここで二重実行を止めます。
      $raw = @file_get_contents($file);
      $state = $raw !== false ? json_decode($raw, true) : null;

      if (!is_array($state) || ($state['last_run'] ?? '') !== $today) {
        prevarsiSweepStaleMatches();

        $body = json_encode(['last_run' => $today], JSON_UNESCAPED_UNICODE);
        if ($body !== false) {
          $tmp = $dir . '/.' . bin2hex(random_bytes(6)) . '.tmp';
          if (@file_put_contents($tmp, $body) !== false) {
            @rename($tmp, $file);
          }
        }
      }

      flock($fp, LOCK_UN);
      fclose($fp);
    }
  }
}

// =====================================================================
// 対局の状態整形(サーバ内部表現 → 呼び出し元(自分自身)の視点のJSON)
// =====================================================================

function prevarsiMatchSummary(array $match, string $myUserId): array {
  $isBlack = $match['black']['userid'] === $myUserId;
  $myColor = $isBlack ? O_BLACK : O_WHITE;
  $opponent = $isBlack ? $match['white'] : $match['black'];
  $status = $match['status'];
  $viewStatus = $status;
  if ($status === 'pending') { $viewStatus = $isBlack ? 'pending_sent' : 'pending_received'; }
  return [
    'match_id' => $match['id'],
    'opponent' => ['username' => $opponent['username'], 'name' => $opponent['name']],
    'my_color' => $myColor,
    'status'   => $viewStatus,
    'my_turn'  => $status === 'active' && (int)$match['turn'] === $myColor,
    'winner'   => $match['winner'],
    'updated'  => $match['updated'],
  ];
}

function prevarsiMatchFullState(array $match, string $myUserId): array {
  $isBlack = $match['black']['userid'] === $myUserId;
  $myColor = $isBlack ? O_BLACK : O_WHITE;
  return [
    'match_id' => $match['id'],
    'board'    => $match['board'],
    'turn'     => $match['turn'],
    'status'   => $match['status'],
    'winner'   => $match['winner'],
    'my_color' => $myColor,
    'my_turn'  => $match['status'] === 'active' && (int)$match['turn'] === $myColor,
    'black'    => ['username' => $match['black']['username'], 'name' => $match['black']['name']],
    'white'    => ['username' => $match['white']['username'], 'name' => $match['white']['name']],
    // 直前に置かれた石の位置。盤面のどこが動いたのかを目で追えるようにするため。
    // last_moveを持たない時期に作られた対局もあるので、無ければnullでよい。
    'last_move' => $match['last_move'] ?? null,
    'updated'  => $match['updated'],
  ];
}

// =====================================================================
// P2P(匿名)対局の合図の一時預かり(音波ハンドシェイク用のシグナリング)
//
// P2Pモードは元々、WebRTCのoffer/answer(base64で2〜4KB)をそのまま
// コピー&ペーストで交換する作りだった。これを音波(quiet.js)で自動化しようと
// すると、音響モデムの1フレームは25〜34バイトしかなく、フレームが1つでも
// 欠けると組み立て中のデータを丸ごと捨てる仕様のため、数KBのSDPを載せるのは
// 現実的に成功しない(p-chatが音波で送っているのも64バイトの招待コードだけ)。
//
// そこでoffer/answerそのものはこのサーバーに一時的に預け、音波では16バイトの
// 「合図コード」だけを飛ばす。預かるのは接続の合図(SDP)だけで、対局中の
// 着手データはここを一切通らない(接続後はブラウザ同士の直接通信のまま)。
// 預かったデータは相手に渡した時点で即削除し、残ってもTTLで自動的に消える。
//
// 合図コードは 32桁の16進文字列 = 前半16桁がid・後半16桁がtokenで、
// p-chatの招待コード(room_id+invite_token)と同じ「コードそのものが権限」
// という考え方。ログイン不要のため、コードを知っていることだけが条件になる。
// =====================================================================

const SIGNALS_FILE = './assets/3362e9bd4f424e988782abc42c863886/signals.jsonl';
/** 預かった合図を放置したまま消す秒数。接続は普通1分もかからない。 */
const SIGNAL_TTL_SECONDS = 600;
/** 同時に預かれる合図の上限。大量作成でディスクを使い切らせないための蓋。 */
const SIGNAL_MAX_ENTRIES = 300;
/** offer/answer 1件あたりの上限バイト数。実際のSDPはbase64で2〜4KB程度。 */
const SIGNAL_MAX_SDP_BYTES = 16384;

function prevarsiSignalsKey(): ?string {
  $hidden = prevarsiHiddenFilesDir();

  if ($hidden === null) {
    $key = null;
  } else {
    $key = prevarsiLoadOrCreateKey($hidden . '/p-reversi_signal_key.php');
  }

  return $key;
}

function prevarsiReadSignals(): array {
  $key = prevarsiSignalsKey();

  if ($key === null) {
    // 鍵が無い環境。暗号化前の平文として読みます(移行前のデータも見えるように)。
    $rows = prevarsiReadJsonl(SIGNALS_FILE);
  } else {
    $rows = prevarsiReadEncryptedJsonlBlob(SIGNALS_FILE, $key);
  }

  return $rows;
}

function prevarsiWriteSignals(array $rows): bool {
  $key = prevarsiSignalsKey();

  if ($key === null) {
    $ok = prevarsiWriteJsonl(SIGNALS_FILE, $rows);
  } else {
    $ok = prevarsiWriteEncryptedJsonlBlob(SIGNALS_FILE, $rows, $key);
  }

  return $ok;
}

function prevarsiSignalTransact(callable $mutator): array {
  $outcome = prevarsiTransactOn(SIGNALS_FILE, 'prevarsiReadSignals', 'prevarsiWriteSignals', $mutator);

  return $outcome;
}

/** 期限切れの合図を捨てる。合図を触るたびに呼ぶので、掃除専用の仕組みは持たない。 */
function prevarsiPruneSignals(array $rows): array {
  $limit = time() - SIGNAL_TTL_SECONDS;
  $kept = array_values(array_filter($rows, static fn($s) => (int)($s['created'] ?? 0) > $limit));

  return $kept;
}

/** 合図コード(32桁の16進文字列)をid(前半16桁)とtoken(後半16桁)に分ける。形式が違えばnull。 */
function prevarsiSignalCodeParts(string $code): ?array {
  // 口頭や音波で伝わる途中で空白や記号が混ざるので、16進以外を先に落とします。
  $code = strtolower((string)preg_replace('/[^0-9A-Fa-f]/', '', $code));

  if (!preg_match('/^[0-9a-f]{32}$/', $code)) {
    $parts = null;
  } else {
    $parts = ['id' => substr($code, 0, 16), 'token' => substr($code, 16, 16)];
  }

  return $parts;
}

/** 合図1件がそのコードのものかどうか。tokenの比較はタイミング攻撃を避けてhash_equalsで行う。 */
function prevarsiSignalMatches(array $signal, array $parts): bool {
  $same = ($signal['id'] ?? '') === $parts['id']
    && hash_equals((string)($signal['token'] ?? ''), $parts['token']);

  return $same;
}

/** ホスト(黒)がofferを預ける。戻り値のcodeを音波なり口頭なりで相手へ渡してもらう。 */
function prevarsiHandleSignalCreate(): array {
  $offer = (string)($_POST['offer'] ?? '');

  if (!prevarsiCsrfIsValid()) {
    $result = ['ok' => false, 'error' => 'invalid_csrf', 'message' => 'ページの有効期限が切れました。再読み込みしてもう一度お試しください。'];

  } else if ($offer === '' || strlen($offer) > SIGNAL_MAX_SDP_BYTES) {
    $result = ['ok' => false, 'error' => 'invalid_input', 'message' => '接続情報を受け取れませんでした。'];

  } else {
    $id = bin2hex(random_bytes(8));
    $token = bin2hex(random_bytes(8));

    $tx = prevarsiSignalTransact(static function (array &$rows) use ($id, $token, $offer): array {
      $rows = prevarsiPruneSignals($rows);

      // 上限に達していても save は true のままにします。上でやった掃除の結果を
      // 捨てないためです(掃除だけは残す)。
      if (count($rows) >= SIGNAL_MAX_ENTRIES) {
        $outcome = ['save' => true, 'result' => ['error' => 'busy']];
      } else {
        $rows[] = ['id' => $id, 'token' => $token, 'offer' => $offer, 'answer' => null, 'created' => time()];
        $outcome = ['save' => true, 'result' => ['error' => null]];
      }

      return $outcome;
    });

    if ($tx['status'] !== true) {
      $result = ['ok' => false, 'error' => 'storage_error', 'message' => '接続情報を預かれませんでした。しばらくしてからもう一度お試しください。'];
    } else if (($tx['result']['error'] ?? null) === 'busy') {
      $result = ['ok' => false, 'error' => 'busy', 'message' => '接続待ちの対局が多すぎます。しばらくしてからもう一度お試しください。'];
    } else {
      $result = ['ok' => true, 'code' => $id . $token, 'ttl' => SIGNAL_TTL_SECONDS];
    }
  }

  return $result;
}

/** ゲスト(白)が合図コードでofferを受け取る。 */
function prevarsiHandleSignalFetch(): array {
  $parts = prevarsiCsrfIsValid() ? prevarsiSignalCodeParts((string)($_POST['code'] ?? '')) : null;
  $offer = null;

  if ($parts !== null) {
    foreach (prevarsiPruneSignals(prevarsiReadSignals()) as $signal) {
      if (prevarsiSignalMatches($signal, $parts)) {
        $offer = (string)$signal['offer'];
        break;
      }
    }
  }

  if (!prevarsiCsrfIsValid()) {
    $result = ['ok' => false, 'error' => 'invalid_csrf', 'message' => 'ページの有効期限が切れました。再読み込みしてもう一度お試しください。'];
  } else if ($parts === null) {
    $result = ['ok' => false, 'error' => 'invalid_code', 'message' => '合図コードの形が正しくありません。'];
  } else if ($offer === null) {
    $result = ['ok' => false, 'error' => 'not_found', 'message' => 'その合図コードの相手が見つかりません。時間が経ちすぎたか、コードが違うようです。'];
  } else {
    $result = ['ok' => true, 'offer' => $offer];
  }

  return $result;
}

/** ゲスト(白)がanswerを預ける。ホスト側のprevarsiHandleSignalPoll()が拾う。 */
function prevarsiHandleSignalAnswer(): array {
  $parts = prevarsiCsrfIsValid() ? prevarsiSignalCodeParts((string)($_POST['code'] ?? '')) : null;
  $answer = (string)($_POST['answer'] ?? '');

  if (!prevarsiCsrfIsValid()) {
    $result = ['ok' => false, 'error' => 'invalid_csrf', 'message' => 'ページの有効期限が切れました。再読み込みしてもう一度お試しください。'];

  } else if ($parts === null) {
    $result = ['ok' => false, 'error' => 'invalid_code', 'message' => '合図コードの形が正しくありません。'];

  } else if ($answer === '' || strlen($answer) > SIGNAL_MAX_SDP_BYTES) {
    $result = ['ok' => false, 'error' => 'invalid_input', 'message' => '接続情報を受け取れませんでした。'];

  } else {
    $tx = prevarsiSignalTransact(static function (array &$rows) use ($parts, $answer): array {
      $rows = prevarsiPruneSignals($rows);
      $found = false;

      foreach ($rows as $i => $signal) {
        if (prevarsiSignalMatches($signal, $parts)) {
          $rows[$i]['answer'] = $answer;
          $found = true;
          break;
        }
      }

      // 見つからなくても save は true。上でやった掃除の結果を捨てないためです。
      return ['save' => true, 'result' => ['found' => $found]];
    });

    if ($tx['status'] !== true) {
      $result = ['ok' => false, 'error' => 'storage_error', 'message' => '接続情報を預かれませんでした。'];
    } else if (empty($tx['result']['found'])) {
      $result = ['ok' => false, 'error' => 'not_found', 'message' => 'その合図コードの相手が見つかりません。時間が経ちすぎたか、コードが違うようです。'];
    } else {
      $result = ['ok' => true];
    }
  }

  return $result;
}

/** ホスト(黒)がanswerが届いたかを見に来る。届いていたら渡したうえで即座に削除する。 */
function prevarsiHandleSignalPoll(): array {
  $parts = prevarsiSignalCodeParts((string)($_GET['code'] ?? ''));

  if ($parts === null) {
    $result = ['ok' => false, 'error' => 'invalid_code', 'message' => '合図コードの形が正しくありません。'];
  } else {
    // これは相手の返事が届くまで数秒おきに繰り返し呼ばれる。中身が何も変わって
    // いないのに毎回ファイルを暗号化して書き直すのは無駄なので、実際に消すものが
    // あったときだけ保存する(prevarsiTransactOn()は保存しなかった場合にstatusを
    // falseで返すため、エラー判定はnull(保存失敗)だけを見る)。
    $tx = prevarsiSignalTransact(static function (array &$rows) use ($parts): array {
      $before = count($rows);
      $rows = prevarsiPruneSignals($rows);
      $pruned = count($rows) !== $before;
      $outcome = null;

      foreach ($rows as $i => $signal) {
        if (prevarsiSignalMatches($signal, $parts)) {
          $answer = $signal['answer'] ?? null;

          if ($answer === null) {
            $outcome = ['save' => $pruned, 'result' => ['state' => 'waiting']];
          } else {
            // 相手の答えを渡したらもう用済み。ここで消して、預かる時間を最短にする。
            array_splice($rows, $i, 1);
            $outcome = ['save' => true, 'result' => ['state' => 'answered', 'answer' => (string)$answer]];
          }
          break;
        }
      }

      // 1件も当たらなかった = 期限切れで消えた後。
      if ($outcome === null) {
        $outcome = ['save' => $pruned, 'result' => ['state' => 'gone']];
      }

      return $outcome;
    });

    $state = $tx['result']['state'] ?? 'gone';

    if ($tx['status'] === null) {
      $result = ['ok' => false, 'error' => 'storage_error', 'message' => '接続情報を確認できませんでした。'];
    } else if ($state === 'waiting') {
      $result = ['ok' => true, 'state' => 'waiting'];
    } else if ($state === 'answered') {
      $result = ['ok' => true, 'state' => 'answered', 'answer' => $tx['result']['answer']];
    } else {
      $result = ['ok' => false, 'error' => 'not_found', 'message' => '合図の有効期限が切れました。もう一度作り直してください。'];
    }
  }

  return $result;
}

/** ホストが途中でやめたときの後始末。呼ばれなくてもTTLで消えるが、待たずに消す。 */
function prevarsiHandleSignalCancel(): array {
  $parts = prevarsiCsrfIsValid() ? prevarsiSignalCodeParts((string)($_POST['code'] ?? '')) : null;

  if (!prevarsiCsrfIsValid()) {
    $result = ['ok' => false, 'error' => 'invalid_csrf', 'message' => 'ページの有効期限が切れました。'];

  } else if ($parts === null) {
    $result = ['ok' => false, 'error' => 'invalid_code', 'message' => '合図コードの形が正しくありません。'];

  } else {
    prevarsiSignalTransact(static function (array &$rows) use ($parts): array {
      $rows = prevarsiPruneSignals($rows);

      foreach ($rows as $i => $signal) {
        if (prevarsiSignalMatches($signal, $parts)) {
          array_splice($rows, $i, 1);
          break;
        }
      }

      return ['save' => true, 'result' => null];
    });

    // 消せたかどうかは問いません。TTLでいずれ消えるので、ここは「頼んだ」で十分です。
    $result = ['ok' => true];
  }

  return $result;
}

// =====================================================================
// コンピュータ対局(一人用)。判定も思考もすべてこのサーバー側で行う。
//
// ブラウザ側にAIを置かず、着手のたびに?api=cpu_move(またはJS無効環境向けの
// 通常のPRGフォーム)でここへ投げ、盤面と次の一手をサーバーが返す。おかげで
// JavaScriptが無くても遊べ、PMeikeeモードと同じ判定エンジン
// (othelloApplyMove/othelloAdvanceTurn)をそのまま正として使える。
//
// 対局の状態はPHPセッションにだけ置く(アカウント不要で遊べる必要があり、
// 一人用の途中経過をmatches.jsonlに残す価値も無いため)。同時に持てるのは
// 1局だけで、新しく始めると前の対局は捨てられる。
// =====================================================================

/**
 * 探索用の盤面表現。othello*()系の8x8二次元配列は読みやすい代わりに
 * 添字アクセスが二重で重く、数千局面を読むαβ探索では効いてくる。CPUの思考の
 * 中だけは64要素の一次元配列に直し、マス番号 sq = row * 8 + col で扱う。
 */
function prevarsiCpuFlatten(array $board): array {
  $flat = [];

  foreach ($board as $row) {
    foreach ($row as $cell) { $flat[] = (int)$cell; }
  }

  return $flat;
}

/**
 * 各マスから8方向へ伸びるマス番号の並びを先に作っておく(64マス x 最大8方向)。
 * 探索中は毎回この表をなぞるだけになり、境界判定を繰り返さずに済む。
 * 長さ1の方向は原理的に石をひっくり返せないので最初から省く。
 */
function prevarsiCpuRays(): array {
  static $rays = null;

  // 2回目以降は $rays が入っているので、この塊ごと素通りして表を使い回します。
  if ($rays === null) {
    $rays = [];

    for ($sq = 0; $sq < 64; $sq++) {
      $row = intdiv($sq, 8);
      $col = $sq % 8;
      $rays[$sq] = [];

      foreach (O_DIRS as [$dr, $dc]) {
        $line = [];
        $r = $row + $dr;
        $c = $col + $dc;
        while ($r >= 0 && $r < 8 && $c >= 0 && $c < 8) {
          $line[] = $r * 8 + $c;
          $r += $dr;
          $c += $dc;
        }
        // 長さ1の方向は原理的に石をひっくり返せないので表に入れません。
        if (count($line) >= 2) { $rays[$sq][] = $line; }
      }
    }
  }

  return $rays;
}

/** 一次元盤面版のothelloFlips()。ひっくり返るマス番号の配列を返す。 */
function prevarsiCpuFlips(array $flat, int $player, int $sq): array {
  $flips = [];

  // 既に石がある場所には置けません(=ひっくり返る石も無い)。
  if ($flat[$sq] === O_EMPTY) {
    $opponent = $player === O_BLACK ? O_WHITE : O_BLACK;

    foreach (prevarsiCpuRays()[$sq] as $ray) {
      $line = [];

      foreach ($ray as $s) {
        $value = $flat[$s];

        if ($value === $opponent) {
          $line[] = $s;
        } else {
          // 相手の石が続いた先が自分の石なら、その間を挟んだことになります。
          if ($value === $player && $line !== []) {
            foreach ($line as $x) { $flips[] = $x; }
          }
          break;
        }
      }
    }
  }

  return $flips;
}

/** 一次元盤面版のothelloLegalMoves()。戻り値は マス番号 => ひっくり返るマスの配列。 */
function prevarsiCpuMoves(array $flat, int $player): array {
  $moves = [];

  for ($sq = 0; $sq < 64; $sq++) {
    if ($flat[$sq] === O_EMPTY) {
      $flips = prevarsiCpuFlips($flat, $player, $sq);
      if ($flips !== []) { $moves[$sq] = $flips; }
    }
  }

  return $moves;
}

function prevarsiCpuPlay(array $flat, int $player, int $sq, array $flips): array {
  $flat[$sq] = $player;
  foreach ($flips as $x) { $flat[$x] = $player; }

  return $flat;
}

/**
 * マスごとの価値。角(120)が最も高く、角の隣(-20/-40)は相手に角を渡しやすい
 * ため大きく減点する、オセロでは古典的な重み付け。
 */
const PREVARSI_CPU_SQUARE_WEIGHTS = [
  120, -20,  20,   5,   5,  20, -20, 120,
  -20, -40,  -5,  -5,  -5,  -5, -40, -20,
   20,  -5,  15,   3,   3,  15,  -5,  20,
    5,  -5,   3,   3,   3,   3,  -5,   5,
    5,  -5,   3,   3,   3,   3,  -5,   5,
   20,  -5,  15,   3,   3,  15,  -5,  20,
  -20, -40,  -5,  -5,  -5,  -5, -40, -20,
  120, -20,  20,   5,   5,  20, -20, 120,
];

/**
 * 局面の点数($meから見て大きいほど有利)。強さの段階によって見るものを変える。
 *   position: マスの価値だけ。角を取り、角の隣を避けるようになる。
 *   full    : マスの価値 + 着手可能数(相手の選択肢を狭められているか)。
 *             終盤は最終的な石数がそのまま勝敗なので、残りマスが減るほど
 *             石数差の比重を上げる。
 */
function prevarsiCpuEvaluate(array $flat, int $me, string $style, int $empties): int {
  $opponent = $me === O_BLACK ? O_WHITE : O_BLACK;

  $mine = 0;
  $theirs = 0;
  $positional = 0;
  foreach ($flat as $sq => $value) {
    if ($value === $me) {
      $mine++;
      $positional += PREVARSI_CPU_SQUARE_WEIGHTS[$sq];
    } elseif ($value === $opponent) {
      $theirs++;
      $positional -= PREVARSI_CPU_SQUARE_WEIGHTS[$sq];
    }
  }

  if ($style === 'position') {
    $score = $positional;
  } else {
    $mobility = count(prevarsiCpuMoves($flat, $me)) - count(prevarsiCpuMoves($flat, $opponent));
    // 終盤ほど石数差の比重を上げます(最後は石数がそのまま勝敗のため)。
    $discWeight = $empties <= 16 ? (20 - $empties) : 0;
    $score = $positional + $mobility * 12 + ($mine - $theirs) * $discWeight;
  }

  return $score;
}

/**
 * αβ法での探索。戻り値は常に$me(CPU自身)から見た点数。
 * $deadlineを過ぎたら$abortedを立ててその場で戻る。打ち切りが起きた深さの
 * 結果は信用できないので、呼び出し元(反復深化)はまるごと捨てる。
 */
function prevarsiCpuSearch(array $flat, int $player, int $me, int $depth, int $alpha, int $beta, string $style, float $deadline, bool &$aborted): int {
  // 場面は上から順に「打ち切り」「終局」「パス」「読み切り」「探索」の5つ。
  // 答えは $score に溜めて最後に1回だけ返します。
  if ($aborted) {
    $score = 0;

  } else if (microtime(true) > $deadline) {
    // 時間切れ。$aborted を立てた回の結果は信用できないので、呼び出し元
    // (反復深化)がその深さの結果をまるごと捨てます。
    $aborted = true;
    $score = 0;

  } else {
    $empties = 0;
    foreach ($flat as $value) {
      if ($value === O_EMPTY) { $empties++; }
    }

    $opponent = $player === O_BLACK ? O_WHITE : O_BLACK;
    $moves = prevarsiCpuMoves($flat, $player);
    // 相手の手を数えるのは、自分が打てないときだけで足ります(短絡評価)。
    $bothStuck = ($moves === []) && (prevarsiCpuMoves($flat, $opponent) === []);

    if ($bothStuck) {
      // 双方打てない = 終局。勝敗そのものなので、評価値ではなく石数差を
      // 桁違いに大きく返し、途中の見た目の良さより確実な勝ちを選ばせる。
      $mine = 0;
      $theirs = 0;
      foreach ($flat as $value) {
        if ($value === $me) { $mine++; } else if ($value !== O_EMPTY) { $theirs++; }
      }
      $score = ($mine - $theirs) * 1000;

    } else if ($moves === []) {
      // パス。手番だけ渡し、深さは消費しない。
      $score = prevarsiCpuSearch($flat, $opponent, $me, $depth, $alpha, $beta, $style, $deadline, $aborted);

    } else if ($depth <= 0) {
      $score = prevarsiCpuEvaluate($flat, $me, $style, $empties);

    } else {
      // 良さそうな手から先に調べるほどαβの枝刈りが効く。ここではマスの価値順に
      // 並べ替えるだけの簡易な指し手順序付けで済ませる。
      $order = array_keys($moves);
      usort($order, static fn($x, $y) => PREVARSI_CPU_SQUARE_WEIGHTS[$y] <=> PREVARSI_CPU_SQUARE_WEIGHTS[$x]);

      if ($player === $me) {
        // 自分の手番。点数が高い方を選びます。
        $best = PHP_INT_MIN;
        foreach ($order as $sq) {
          $child = prevarsiCpuPlay($flat, $player, $sq, $moves[$sq]);
          $childScore = prevarsiCpuSearch($child, $opponent, $me, $depth - 1, $alpha, $beta, $style, $deadline, $aborted);
          if ($aborted) { break; }
          if ($childScore > $best) { $best = $childScore; }
          if ($best > $alpha) { $alpha = $best; }
          if ($beta <= $alpha) { break; }
        }
        $score = $best;

      } else {
        // 相手の手番。相手はこちらの点数が低くなる方を選びます。
        $best = PHP_INT_MAX;
        foreach ($order as $sq) {
          $child = prevarsiCpuPlay($flat, $player, $sq, $moves[$sq]);
          $childScore = prevarsiCpuSearch($child, $opponent, $me, $depth - 1, $alpha, $beta, $style, $deadline, $aborted);
          if ($aborted) { break; }
          if ($childScore < $best) { $best = $childScore; }
          if ($best < $beta) { $beta = $best; }
          if ($beta <= $alpha) { break; }
        }
        $score = $best;
      }
    }
  }

  return $score;
}

/**
 * 強さの段階(1〜10)ごとの設定。
 *   depth   : 通常時に読む手数の上限
 *   style   : prevarsiCpuEvaluate()の評価方法
 *   blunder : わざと合法手からでたらめに選ぶ確率
 *   endgame : 残りマスがこの数以下なら最後まで読み切りにいく
 *   seconds : 1手にかけてよい秒数の上限(反復深化の打ち切り時刻)
 *
 * 弱さの作り方は、低い段階ではblunder(でたらめに打つ確率)、高い段階では
 * 読みの深さ、という役割分担にしてある。実際に総当たりさせて決めた並びで、
 * 隣り合う段階どうしでも上の段階が勝ち越すことを確認済み。
 *
 * 「石数だけを見て多く取ろうとする」評価も初心者らしい打ち方として試したが、
 * 実測ではでたらめに打つより明確に強く(300局で勝率63.7%)、blunderを上下
 * させても段階どうしの差がほとんど付かなかったため採用していない。弱さは
 * blunder1本で作った方が、段階の順序が素直に単調になる。
 */
const PREVARSI_CPU_LEVELS = [
  1  => ['depth' => 1, 'style' => 'position', 'blunder' => 0.90, 'endgame' => 0,  'seconds' => 0.5],
  2  => ['depth' => 1, 'style' => 'position', 'blunder' => 0.75, 'endgame' => 0,  'seconds' => 0.5],
  3  => ['depth' => 1, 'style' => 'position', 'blunder' => 0.60, 'endgame' => 0,  'seconds' => 0.5],
  4  => ['depth' => 1, 'style' => 'position', 'blunder' => 0.40, 'endgame' => 0,  'seconds' => 0.5],
  5  => ['depth' => 1, 'style' => 'position', 'blunder' => 0.20, 'endgame' => 0,  'seconds' => 0.8],
  6  => ['depth' => 2, 'style' => 'position', 'blunder' => 0.10, 'endgame' => 4,  'seconds' => 1.0],
  7  => ['depth' => 3, 'style' => 'full',     'blunder' => 0.05, 'endgame' => 6,  'seconds' => 1.2],
  8  => ['depth' => 4, 'style' => 'full',     'blunder' => 0.00, 'endgame' => 10, 'seconds' => 1.6],
  9  => ['depth' => 6, 'style' => 'full',     'blunder' => 0.00, 'endgame' => 12, 'seconds' => 2.2],
  10 => ['depth' => 8, 'style' => 'full',     'blunder' => 0.00, 'endgame' => 16, 'seconds' => 2.8],
];

const PREVARSI_CPU_LEVEL_LABELS = [
  1  => 'レベル1 - はじめて',
  2  => 'レベル2 - とてもよわい',
  3  => 'レベル3 - よわい',
  4  => 'レベル4 - すこしよわい',
  5  => 'レベル5 - ふつう',
  6  => 'レベル6 - すこしつよい',
  7  => 'レベル7 - つよい',
  8  => 'レベル8 - かなりつよい',
  9  => 'レベル9 - とてもつよい',
  10 => 'レベル10 - 本気',
];

const PREVARSI_CPU_DEFAULT_LEVEL = 5;

/**
 * CPUの一手を決める。マス番号を返し、打てる手が無ければnull。
 *
 * 反復深化(浅い方から順に読み直す)にしてあるのは、PHPでの探索速度が
 * サーバーの状態や局面の枝数に左右されるためで、時間切れになっても必ず
 * 1つ前の深さの結論が残る。おかげで「レベル10で数十秒待たされる」ことも
 * 「時間切れで手が決まらない」ことも起きない。
 */
function prevarsiCpuChooseMove(array $flat, int $me, int $level): ?int {
  $moves = prevarsiCpuMoves($flat, $me);
  $squares = array_keys($moves);
  $config = PREVARSI_CPU_LEVELS[$level] ?? PREVARSI_CPU_LEVELS[PREVARSI_CPU_DEFAULT_LEVEL];
  // わざと間違える確率。低いレベルの弱さはこれ1本で作っています。
  $blunders = ($moves !== [])
    && $config['blunder'] > 0
    && mt_rand(1, 1000) <= (int)round($config['blunder'] * 1000);

  if ($moves === []) {
    $best = null;

  } else if ($blunders) {
    $best = $squares[array_rand($squares)];

  } else {
    $empties = 0;
    foreach ($flat as $value) {
      if ($value === O_EMPTY) { $empties++; }
    }

    // 残りマスが少なければ最後まで読み切りにいきます。
    $maxDepth = $config['depth'];
    if ($config['endgame'] > 0 && $empties <= $config['endgame']) {
      $maxDepth = max($maxDepth, $empties);
    }

    $deadline = microtime(true) + $config['seconds'];
    $opponent = $me === O_BLACK ? O_WHITE : O_BLACK;
    // 1手も読めずに時間切れになっても、必ず合法手が1つ残るようにしておきます。
    $best = $squares[0];

    // 反復深化。浅い方から読み直すので、途中で時間切れになっても
    // 必ず1つ前の深さの結論が $best に残ります。
    for ($depth = 1; $depth <= $maxDepth; $depth++) {
      $aborted = false;
      $alpha = PHP_INT_MIN;
      $bestThisDepth = null;
      $bestScore = PHP_INT_MIN;

      foreach ($squares as $sq) {
        $child = prevarsiCpuPlay($flat, $me, $sq, $moves[$sq]);
        $score = prevarsiCpuSearch($child, $opponent, $me, $depth - 1, $alpha, PHP_INT_MAX, $config['style'], $deadline, $aborted);
        if ($aborted) { break; }

        if ($bestThisDepth === null || $score > $bestScore) {
          $bestScore = $score;
          $bestThisDepth = $sq;
        }
        if ($bestScore > $alpha) { $alpha = $bestScore; }
      }

      // 【打ち切った深さの結果は採らないこと】途中までしか読めていないので、
      // その深さの結論は信用できません。1つ前の深さの $best をそのまま使います。
      if ($aborted) { break; }
      $best = $bestThisDepth;
    }
  }

  return $best;
}

/** セッションに置いてある進行中のCPU対局。無ければnull。 */
function prevarsiCpuGame(): ?array {
  $game = $_SESSION['prevarsi_cpu'] ?? null;

  return is_array($game) ? $game : null;
}

/**
 * 着手後の手番進行と終局判定。PMeikeeモードと同じothelloAdvanceTurn()を
 * 正として、その結果をCPU対局の状態へ書き写すだけにしてある。
 */
function prevarsiCpuAdvance(array $game, int $movedColor): array {
  $adv = othelloAdvanceTurn($game['board'], $movedColor);

  if ($adv['game_over']) {
    $score = othelloScore($game['board']);
    $game['status'] = 'finished';

    if ($score['black'] === $score['white']) {
      $game['winner'] = 0; // 引き分け
    } else if ($score['black'] > $score['white']) {
      $game['winner'] = O_BLACK;
    } else {
      $game['winner'] = O_WHITE;
    }
    $game['passed'] = null;

  } else {
    $game['turn'] = $adv['turn'];

    // 手番が打った本人に戻ってきた = 相手に置けるマスが無くパスになった。
    if ((int)$adv['turn'] === $movedColor) {
      $game['passed'] = $movedColor === (int)$game['my_color'] ? 'cpu' : 'you';
    } else {
      $game['passed'] = null;
    }
  }

  return $game;
}

/**
 * CPUの手番である限りCPUに打たせ続ける。パスが挟まると人間の手番が飛ばされて
 * 再びCPUの番になることがあるため、1回きりではなくループにしてある。
 */
function prevarsiCpuLetComputerPlay(array $game): array {
  $cpuColor = othelloOpponent((int)$game['my_color']);
  $game['last_cpu'] = null;
  $stuck = false;

  // パスが挟まると人間の手番が飛ばされて再びCPUの番になるので、1回きりではなくループです。
  while ($stuck === false && $game['status'] === 'active' && (int)$game['turn'] === $cpuColor) {
    $sq = prevarsiCpuChooseMove(prevarsiCpuFlatten($game['board']), $cpuColor, (int)$game['level']);
    $next = ($sq === null)
      ? null
      : othelloApplyMove($game['board'], $cpuColor, intdiv($sq, 8), $sq % 8);

    if ($next === null) {
      // 打つ手が無い、または判定エンジンが反則と答えた。ここで止めます。
      $stuck = true;
    } else {
      $row = intdiv($sq, 8);
      $col = $sq % 8;
      $game['board'] = $next;
      // last_cpu は「この応答でコンピュータが打ったか」の印(ブラウザ側が知らせの音を
      // 鳴らすかどうかの判断に使う)。last_move は誰が置いたかに関わらず「いちばん
      // 最後に置かれた石」で、盤面の印はこちらを見る(PMeikeeモードと同じ形)。
      $game['last_cpu'] = [$row, $col];
      $game['last_move'] = ['row' => $row, 'col' => $col, 'color' => $cpuColor];
      $game = prevarsiCpuAdvance($game, $cpuColor);
    }
  }

  return $game;
}

function prevarsiCpuStartGame(int $level, int $myColor): array {
  $game = [
    'board'    => othelloInitialBoard(),
    'turn'     => O_BLACK,
    'my_color' => $myColor,
    'level'    => $level,
    'status'   => 'active',
    'winner'    => null,
    'last_cpu'  => null,
    'last_move' => null,
    'passed'    => null,
    'created'   => time(),
  ];
  // 自分が白(後手)を選んだ場合は、この時点でCPU(黒)の初手を打たせておく。
  $game = prevarsiCpuLetComputerPlay($game);
  $_SESSION['prevarsi_cpu'] = $game;
  return $game;
}

/** CPU対局の状態を、呼び出し元(自分自身)の視点のJSONに整える。 */
function prevarsiCpuViewState(array $game): array {
  $view = [
    'board'    => $game['board'],
    'turn'     => $game['turn'],
    'my_color' => (int)$game['my_color'],
    'level'    => (int)$game['level'],
    'status'   => $game['status'],
    'winner'   => $game['winner'],
    'my_turn'  => $game['status'] === 'active' && (int)$game['turn'] === (int)$game['my_color'],
    'last_cpu' => $game['last_cpu'],
    // last_moveを持たない時期に始まった対局が残っていても壊れないようにしておく。
    'last_move' => $game['last_move'] ?? null,
    'passed'   => $game['passed'],
    'score'    => othelloScore($game['board']),
  ];

  return $view;
}

function prevarsiHandleCpuState(): array {
  $game = prevarsiCpuGame();

  if ($game === null) {
    $result = ['ok' => true, 'game' => null];
  } else {
    $result = ['ok' => true, 'game' => prevarsiCpuViewState($game)];
  }

  return $result;
}

function prevarsiHandleCpuNew(): array {
  if (!prevarsiCsrfIsValid()) {
    $result = ['ok' => false, 'error' => 'invalid_csrf', 'message' => 'ページの有効期限が切れました。再読み込みしてもう一度お試しください。'];
  } else {
    // 知らないレベルが送られてきたら既定へ倒します(そのまま使うと設定が引けません)。
    $given = (int)($_POST['level'] ?? PREVARSI_CPU_DEFAULT_LEVEL);
    $level = isset(PREVARSI_CPU_LEVELS[$given]) ? $given : PREVARSI_CPU_DEFAULT_LEVEL;

    $colorChoice = (string)($_POST['my_color'] ?? 'black');
    if ($colorChoice === 'white') {
      $myColor = O_WHITE;
    } else if ($colorChoice === 'random') {
      $myColor = mt_rand(0, 1) === 0 ? O_BLACK : O_WHITE;
    } else {
      $myColor = O_BLACK;
    }

    $result = ['ok' => true, 'game' => prevarsiCpuViewState(prevarsiCpuStartGame($level, $myColor))];
  }

  return $result;
}

function prevarsiHandleCpuMove(): array {
  $game = prevarsiCsrfIsValid() ? prevarsiCpuGame() : null;
  $row = (int)($_POST['row'] ?? -1);
  $col = (int)($_POST['col'] ?? -1);
  // 盤へ置けるかどうかの判定は、必ず判定エンジンに答えさせます(ここで自前に判定しない)。
  $next = ($game !== null && $game['status'] === 'active' && (int)$game['turn'] === (int)$game['my_color'])
    ? othelloApplyMove($game['board'], (int)$game['my_color'], $row, $col)
    : null;

  if (!prevarsiCsrfIsValid()) {
    $result = ['ok' => false, 'error' => 'invalid_csrf', 'message' => 'ページの有効期限が切れました。再読み込みしてもう一度お試しください。'];

  } else if ($game === null) {
    $result = ['ok' => false, 'error' => 'no_game', 'message' => '対局が始まっていません。'];

  } else if ($game['status'] !== 'active') {
    $result = ['ok' => false, 'error' => 'not_active', 'message' => 'この対局はすでに終わっています。'];

  } else if ((int)$game['turn'] !== (int)$game['my_color']) {
    $result = ['ok' => false, 'error' => 'not_your_turn', 'message' => 'あなたの番ではありません。'];

  } else if ($next === null) {
    $result = ['ok' => false, 'error' => 'illegal_move', 'message' => 'そこには置けません。'];

  } else {
    $game['board'] = $next;
    // この直後にコンピュータが打てば上書きされる。コンピュータがパスして手番が
    // 戻ってきた場合は、自分のこの手が「いちばん最後に置かれた石」として残る。
    $game['last_move'] = ['row' => $row, 'col' => $col, 'color' => (int)$game['my_color']];
    $game = prevarsiCpuAdvance($game, (int)$game['my_color']);
    $game = prevarsiCpuLetComputerPlay($game);
    $_SESSION['prevarsi_cpu'] = $game;
    $result = ['ok' => true, 'game' => prevarsiCpuViewState($game)];
  }

  return $result;
}

function prevarsiHandleCpuResign(): array {
  $game = prevarsiCsrfIsValid() ? prevarsiCpuGame() : null;

  if (!prevarsiCsrfIsValid()) {
    $result = ['ok' => false, 'error' => 'invalid_csrf', 'message' => 'ページの有効期限が切れました。再読み込みしてもう一度お試しください。'];

  } else if ($game === null || $game['status'] !== 'active') {
    $result = ['ok' => false, 'error' => 'not_active', 'message' => '投了できる対局がありません。'];

  } else {
    $game['status'] = 'finished';
    $game['winner'] = othelloOpponent((int)$game['my_color']);
    $game['passed'] = null;
    $_SESSION['prevarsi_cpu'] = $game;
    $result = ['ok' => true, 'game' => prevarsiCpuViewState($game)];
  }

  return $result;
}

// =====================================================================
// 自前API(?api=...)の各アクション
// =====================================================================

function prevarsiHandleList(array $currentUser): array {
  $myId = $currentUser['userid'];
  $mine = [];

  foreach (prevarsiReadMatches() as $m) {
    if (($m['black']['userid'] ?? null) === $myId || ($m['white']['userid'] ?? null) === $myId) {
      $mine[] = prevarsiMatchSummary($m, $myId);
    }
  }
  usort($mine, static fn($a, $b) => $b['updated'] <=> $a['updated']);

  return ['ok' => true, 'matches' => $mine];
}

function prevarsiHandleInvite(array $currentUser): array {
  $username = isset($_POST['opponent_username']) ? trim((string)$_POST['opponent_username']) : '';
  // profile() は「見つかった相手そのもの」か null を返します(以前の生API応答とは形が
  // 違います)。null のときは、居ないのか・聞けなかったのかを必ず見分けること。
  // 混ぜると、メイキィが一瞬詰まっただけで実在する相手を「見つかりません」と断定します。
  $opponent = (prevarsiCsrfIsValid() && $username !== '') ? PrevarsiAccount::profile($username) : null;

  if (!prevarsiCsrfIsValid()) {
    $result = ['ok' => false, 'error' => 'invalid_csrf', 'message' => 'ページの有効期限が切れました。再読み込みしてもう一度お試しください。'];

  } else if ($username === '') {
    $result = ['ok' => false, 'error' => 'invalid_input', 'message' => '対戦相手のユーザー名を入力してください。'];

  } else if ($opponent === null && PrevarsiAccount::lastFailureWasConnection()) {
    $result = ['ok' => false, 'error' => 'unreachable', 'message' => 'プシューメイキィに接続できませんでした。しばらくしてから再度お試しください。'];

  } else if ($opponent === null) {
    $result = ['ok' => false, 'error' => 'not_found', 'message' => 'そのユーザー名のプシューメイキィが見つかりません。'];

  } else if ($opponent['userid'] === $currentUser['userid']) {
    $result = ['ok' => false, 'error' => 'invalid_input', 'message' => '自分自身を招待することはできません。'];

  } else {
    $match = [
      'id'      => bin2hex(random_bytes(12)),
      'black'   => ['userid' => $currentUser['userid'], 'username' => $currentUser['username'], 'name' => $currentUser['name']],
      'white'   => ['userid' => $opponent['userid'], 'username' => $opponent['username'], 'name' => $opponent['name']],
      'board'   => othelloInitialBoard(),
      'turn'    => O_BLACK,
      'status'  => 'pending',
      'winner'  => null,
      'created' => time(),
      'updated' => time(),
    ];

    $tx = prevarsiTransact(static function (array &$rows) use ($match): array {
      $rows[] = $match;
      return ['save' => true, 'result' => $match];
    });

    if ($tx['status'] !== true) {
      $result = ['ok' => false, 'error' => 'storage_error', 'message' => '招待の保存に失敗しました。しばらくしてからもう一度お試しください。'];
    } else {
      $result = ['ok' => true, 'message' => '招待を送りました。相手が受けると対局が始まります。', 'match_id' => $match['id']];
    }
  }

  return $result;
}

function prevarsiHandleAccept(array $currentUser): array {
  $matchId = (string)($_POST['match_id'] ?? '');
  $myId = $currentUser['userid'];

  // 承諾の前に、招待した側がまだ居るかを確かめます。退会済みの相手と
  // 対局を始めても、相手はもう指せません。
  $matches = prevarsiCsrfIsValid() ? prevarsiReadMatches() : [];
  $previewIdx = prevarsiFindMatchIndex($matches, $matchId);
  $blackId = ($previewIdx === null) ? '' : (string)($matches[$previewIdx]['black']['userid'] ?? '');
  $blackGone = ($blackId !== '') && !prevarsiCanActivateMatch($blackId);

  if (!prevarsiCsrfIsValid()) {
    $result = ['ok' => false, 'error' => 'invalid_csrf', 'message' => 'ページの有効期限が切れました。再読み込みしてもう一度お試しください。'];

  } else if ($blackGone) {
    $result = ['ok' => false, 'error' => 'not_found', 'message' => 'この招待の作成者は既に退会しています。'];

  } else {
    $tx = prevarsiTransact(static function (array &$rows) use ($matchId, $myId): array {
      $idx = prevarsiFindMatchIndex($rows, $matchId);

      // 招待された本人(白)からの、まだ承諾前のものだけを通します。
      if ($idx === null || $rows[$idx]['status'] !== 'pending' || $rows[$idx]['white']['userid'] !== $myId) {
        $outcome = ['save' => false, 'result' => null];
      } else {
        $rows[$idx]['status'] = 'active';
        $rows[$idx]['updated'] = time();
        $outcome = ['save' => true, 'result' => true];
      }

      return $outcome;
    });

    if ($tx['status'] === true && $tx['result']) {
      $result = ['ok' => true, 'message' => '対局を開始しました。'];
    } else if ($tx['status'] === null) {
      $result = ['ok' => false, 'error' => 'storage_error', 'message' => '保存に失敗しました。'];
    } else {
      $result = ['ok' => false, 'error' => 'not_found', 'message' => '招待が見つかりません。'];
    }
  }

  return $result;
}

function prevarsiHandleDecline(array $currentUser): array {
  $matchId = (string)($_POST['match_id'] ?? '');
  $myId = $currentUser['userid'];

  if (!prevarsiCsrfIsValid()) {
    $result = ['ok' => false, 'error' => 'invalid_csrf', 'message' => 'ページの有効期限が切れました。再読み込みしてもう一度お試しください。'];
  } else {
    $tx = prevarsiTransact(static function (array &$rows) use ($matchId, $myId): array {
      $idx = prevarsiFindMatchIndex($rows, $matchId);

      // 招待された本人(白)からの、まだ承諾前のものだけを通します。
      if ($idx === null || $rows[$idx]['status'] !== 'pending' || $rows[$idx]['white']['userid'] !== $myId) {
        $outcome = ['save' => false, 'result' => null];
      } else {
        array_splice($rows, $idx, 1);
        $outcome = ['save' => true, 'result' => true];
      }

      return $outcome;
    });

    if ($tx['status'] === true && $tx['result']) {
      $result = ['ok' => true, 'message' => '招待を断りました。'];
    } else if ($tx['status'] === null) {
      $result = ['ok' => false, 'error' => 'storage_error', 'message' => '保存に失敗しました。'];
    } else {
      $result = ['ok' => false, 'error' => 'not_found', 'message' => '招待が見つかりません。'];
    }
  }

  return $result;
}

function prevarsiHandleMatchState(array $currentUser): array {
  $matchId = (string)($_GET['match_id'] ?? '');
  $myId = $currentUser['userid'];
  $matches = prevarsiReadMatches();
  $idx = prevarsiFindMatchIndex($matches, $matchId);
  $match = ($idx === null) ? null : $matches[$idx];
  $mine = ($match !== null)
    && ($match['black']['userid'] === $myId || $match['white']['userid'] === $myId);

  // activeな対局については、相手のアカウントがまだ存在するかをここで確認し、
  // 既に削除されていれば自分のローカルデータもその場で片付ける
  // (P_DRIVE_INTEGRATION_SPEC.md 7節の設計方針。誰も開かなくても掃除される
  // ための能動的な定期スイープは別途prevarsiSweepStaleMatches()で行う)。
  $peerId = '';
  if ($mine && ($match['status'] ?? '') === 'active') {
    $isBlack = $match['black']['userid'] === $myId;
    $peerId = (string)($isBlack ? $match['white']['userid'] : $match['black']['userid']);
  }
  $peerGone = ($peerId !== '') && !prevarsiAccountExists($peerId);

  if ($idx === null) {
    $result = ['ok' => false, 'error' => 'not_found', 'message' => '対局が見つかりません。'];

  } else if (!$mine) {
    $result = ['ok' => false, 'error' => 'forbidden', 'message' => 'この対局にアクセスする権限がありません。'];

  } else if ($peerGone) {
    prevarsiDeleteMatchInternal($matchId);
    $result = ['ok' => false, 'error' => 'not_found', 'message' => '相手のアカウントが削除されたため、この対局は終了しました。'];

  } else {
    $result = ['ok' => true, 'match' => prevarsiMatchFullState($match, $myId)];
  }

  return $result;
}

function prevarsiHandleMove(array $currentUser): array {
  $matchId = (string)($_POST['match_id'] ?? '');
  $row = (int)($_POST['row'] ?? -1);
  $col = (int)($_POST['col'] ?? -1);
  $myId = $currentUser['userid'];

  if (!prevarsiCsrfIsValid()) {
    $result = ['ok' => false, 'error' => 'invalid_csrf', 'message' => 'ページの有効期限が切れました。再読み込みしてもう一度お試しください。'];
  } else {
    // 【判定はすべてロックの中で行うこと】ロックの外で見て中で書くと、
    // 2人が同時に着手したときに、後から来た方が古い盤面を上書きします。
    $tx = prevarsiTransact(static function (array &$rows) use ($matchId, $myId, $row, $col): array {
      $idx = prevarsiFindMatchIndex($rows, $matchId);
      $match = ($idx === null) ? null : $rows[$idx];
      $mine = ($match !== null)
        && ($match['black']['userid'] === $myId || $match['white']['userid'] === $myId);
      $myColor = ($match !== null && $match['black']['userid'] === $myId) ? O_BLACK : O_WHITE;
      // 置けるかどうかは必ず判定エンジンに答えさせます(ここで自前に判定しない)。
      $nextBoard = ($mine && $match['status'] === 'active' && (int)$match['turn'] === $myColor)
        ? othelloApplyMove($match['board'], $myColor, $row, $col)
        : null;

      if ($idx === null) {
        $outcome = ['save' => false, 'result' => ['error' => 'not_found']];

      } else if (!$mine) {
        $outcome = ['save' => false, 'result' => ['error' => 'forbidden']];

      } else if ($match['status'] !== 'active') {
        $outcome = ['save' => false, 'result' => ['error' => 'not_active']];

      } else if ((int)$match['turn'] !== $myColor) {
        $outcome = ['save' => false, 'result' => ['error' => 'not_your_turn']];

      } else if ($nextBoard === null) {
        $outcome = ['save' => false, 'result' => ['error' => 'illegal_move']];

      } else {
        $match['board'] = $nextBoard;
        $match['last_move'] = ['row' => $row, 'col' => $col, 'color' => $myColor];
        $adv = othelloAdvanceTurn($nextBoard, $myColor);

        if ($adv['game_over']) {
          $score = othelloScore($nextBoard);
          $match['status'] = 'finished';
          $match['winner'] = $score['black'] === $score['white'] ? 0 : ($score['black'] > $score['white'] ? O_BLACK : O_WHITE);
        } else {
          $match['turn'] = $adv['turn'];
        }

        $match['updated'] = time();
        $rows[$idx] = $match;
        $outcome = ['save' => true, 'result' => ['match' => $match]];
      }

      return $outcome;
    });

    $errorMessages = [
      'not_found'     => '対局が見つかりません。',
      'forbidden'     => 'この対局にアクセスする権限がありません。',
      'not_active'    => 'この対局はすでに終了しています。',
      'not_your_turn' => 'あなたの番ではありません。',
      'illegal_move'  => 'そこには置けません。',
    ];

    if ($tx['status'] === true) {
      $result = ['ok' => true, 'match' => prevarsiMatchFullState($tx['result']['match'], $myId)];
    } else if ($tx['status'] === null) {
      $result = ['ok' => false, 'error' => 'storage_error', 'message' => '保存に失敗しました。'];
    } else {
      $error = $tx['result']['error'] ?? 'unknown';
      $result = ['ok' => false, 'error' => $error, 'message' => $errorMessages[$error] ?? '着手できませんでした。'];
    }
  }

  return $result;
}

function prevarsiHandleResign(array $currentUser): array {
  $matchId = (string)($_POST['match_id'] ?? '');
  $myId = $currentUser['userid'];

  if (!prevarsiCsrfIsValid()) {
    $result = ['ok' => false, 'error' => 'invalid_csrf', 'message' => 'ページの有効期限が切れました。再読み込みしてもう一度お試しください。'];
  } else {
    $tx = prevarsiTransact(static function (array &$rows) use ($matchId, $myId): array {
      $idx = prevarsiFindMatchIndex($rows, $matchId);
      $match = ($idx === null) ? null : $rows[$idx];
      $mine = ($match !== null)
        && ($match['black']['userid'] === $myId || $match['white']['userid'] === $myId);

      if ($idx === null) {
        $outcome = ['save' => false, 'result' => ['error' => 'not_found']];

      } else if (!$mine) {
        $outcome = ['save' => false, 'result' => ['error' => 'forbidden']];

      } else if ($match['status'] !== 'active') {
        $outcome = ['save' => false, 'result' => ['error' => 'not_active']];

      } else {
        $myColor = $match['black']['userid'] === $myId ? O_BLACK : O_WHITE;
        $match['status'] = 'finished';
        $match['winner'] = othelloOpponent($myColor);
        $match['updated'] = time();
        $rows[$idx] = $match;
        $outcome = ['save' => true, 'result' => ['match' => $match]];
      }

      return $outcome;
    });

    if ($tx['status'] === true) {
      $result = ['ok' => true, 'match' => prevarsiMatchFullState($tx['result']['match'], $myId)];
    } else if ($tx['status'] === null) {
      $result = ['ok' => false, 'error' => 'storage_error', 'message' => '保存に失敗しました。'];
    } else {
      $result = ['ok' => false, 'error' => 'not_found', 'message' => '対局が見つかりません。'];
    }
  }

  return $result;
}

// =====================================================================
// ルーティング
// =====================================================================

prevarsiRunDailyMaintenanceIfNeeded();

if (isset($_GET['api'])) {
  header('Content-Type: application/json; charset=UTF-8');
  $action = (string)$_GET['api'];

  if ($action === 'whoami') {
    $user = prevarsiCurrentUser();
    echo json_encode(['ok' => true, 'user' => $user, 'csrf' => prevarsiCsrfToken()], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // ログイン不要のAPI。P2Pの合図(signal_*)もコンピュータ対局(cpu_*)も
  // アカウントの概念を持たないため、下のログイン確認より前で処理する。
  $anonymousActions = [
    'signal_create' => 'prevarsiHandleSignalCreate',
    'signal_fetch'  => 'prevarsiHandleSignalFetch',
    'signal_answer' => 'prevarsiHandleSignalAnswer',
    'signal_poll'   => 'prevarsiHandleSignalPoll',
    'signal_cancel' => 'prevarsiHandleSignalCancel',
    'cpu_state'     => 'prevarsiHandleCpuState',
    'cpu_new'       => 'prevarsiHandleCpuNew',
    'cpu_move'      => 'prevarsiHandleCpuMove',
    'cpu_resign'    => 'prevarsiHandleCpuResign',
  ];
  if (isset($anonymousActions[$action])) {
    echo json_encode($anonymousActions[$action](), JSON_UNESCAPED_UNICODE);
    exit;
  }

  $currentUser = prevarsiCurrentUser();
  if ($currentUser === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not_logged_in', 'message' => 'ログインが必要です。'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  switch ($action) {
    case 'list':         echo json_encode(prevarsiHandleList($currentUser), JSON_UNESCAPED_UNICODE); break;
    case 'invite':        echo json_encode(prevarsiHandleInvite($currentUser), JSON_UNESCAPED_UNICODE); break;
    case 'accept':        echo json_encode(prevarsiHandleAccept($currentUser), JSON_UNESCAPED_UNICODE); break;
    case 'decline':        echo json_encode(prevarsiHandleDecline($currentUser), JSON_UNESCAPED_UNICODE); break;
    case 'match_state':  echo json_encode(prevarsiHandleMatchState($currentUser), JSON_UNESCAPED_UNICODE); break;
    case 'move':          echo json_encode(prevarsiHandleMove($currentUser), JSON_UNESCAPED_UNICODE); break;
    case 'resign':        echo json_encode(prevarsiHandleResign($currentUser), JSON_UNESCAPED_UNICODE); break;
    default:
      http_response_code(404);
      echo json_encode(['ok' => false, 'error' => 'unknown_action', 'message' => '不明なAPIです。'], JSON_UNESCAPED_UNICODE);
  }
  exit;
}

// ログインの往復(ログインへのリンクから戻ってきたときの受け口)
//
// 手順そのものは全プロダクト共通なので、共有スクリプトに任せます。
// p-reversi固有なのは1点だけで、それを silent として渡しています。
//
//   無音ログイン(勝手にメイキィへ往復して自動ログインする動き)は行わない
//   ——対局中に画面が飛ぶのを避けるため
//
// 【往復を自前で書き直さないこと】
// 以前ここには、共通クラスと同じ手順を写した受け口が並んでいました。当時は
// 「共通クラスは全プロダクトで一字一句同じ」という決まりで、上の事情をあちらへ
// 入れられなかったからです。しかしその結果、**引き渡しコードを交換する前の照合**が
// pips・p-drive・p-chat・p-reversi の4箇所に手書きで複製されていました。
// あの照合は、コードを盗んだ第三者が自分のブラウザでこのページを開くだけで、
// こちらが代わりに交換して盗んだ側へ被害者のトークンを渡してしまうのを防ぐ、
// 唯一の砦です。1箇所でも書き忘れれば穴が開き、しかも普段は正常に動くので
// 誰も気づけません。固有の事情は options で表現できます。
if (function_exists('pusyuuAccountHandleSignIn')) {
  pusyuuAccountHandleSignIn(
    static function (): bool {
      return prevarsiAccountsToken() !== '';
    },
    static function (array $user, string $token): void {
      PrevarsiSession::regenerateId(); // ログイン=権限昇格。セッション固定化を防ぐ
      $_SESSION['pusyuu_accounts_token'] = $token;
    },
    [
      // 無音ログインは一切行いません。対局中に画面が飛ぶのを避けるためで、
      // 利用者が自分でログインを押したとき(引き渡しコード付きの復路)だけ受け付けます。
      'silent' => false,
    ]
  );
}

// ログインに失敗していたら、その理由を画面へ出すために受けておきます。
// 拾わないと、失敗は誰にも見えません(判定はリダイレクトの直前で終わり、その
// リクエストはそのまま消えるため)。読めるのは1度だけなので変数へ受けます。
$prevarsiSignInError = PrevarsiAccount::takeSignInError();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['account_action'] ?? '') === 'logout') {
  prevarsiLogout();
  // p-revarsi自身のトークンを失効させただけでは、accounts自身のログイン状態が
  // 生きたままになる。次回「ログイン」を押したときに確認無しで再ログインさせない
  // よう、accountsのログアウト画面を一瞬経由してから戻る(spec 3.1節参照)。
  header('Location: ' . PrevarsiAccount::logoutUrl());
  exit;
}

// =====================================================================
// 表示設定「直前に置かれた石に印をつける」の切り替え。
//
// 設定をcookieに置いているのは、盤面を描く場所がJS版(assets/game.js)と
// JS無効版(このファイル)の2箇所あり、どちらからも同じ1つの設定を読み書き
// する必要があるため(localStorageではPHP側から読めない)。JS版はcookieを
// その場で書き換えて描き直すだけなので、このPRGの受け口はJS無効環境専用。
// =====================================================================
const LAST_MOVE_MARK_COOKIE = 'prevarsi_last_move_mark';

function prevarsiShowLastMoveMark(): bool {
  return ($_COOKIE[LAST_MOVE_MARK_COOKIE] ?? '1') !== '0';
}

// =====================================================================
// JS無効時に、盤面の位置を見失わないための目印
//
// JS版は盤面をその場で描き替えるだけなので画面は動かない。一方JS無効版は、
// 着手も相手の手番の確認も**ページの読み込み直し**で行うため、そのたびに
// 表示がページの一番上へ戻る。盤面はロビーや説明文より下にあるので、
// 一手指すたびに毎回スクロールし直すことになり、対局にならない。
//
// そこで盤面に目印(id)を付け、JS無効版の遷移先の末尾に "#" で指しておく。
// ブラウザは読み込み後にその位置まで送ってくれるので、盤面が画面に残る。
//
// 【JS版には影響しない】JS版の着手は ?api=... へfetchで送るので、ここで
// 目印を付けているLocationヘッダの経路をそもそも通らない。目印のidを出すのも
// JS無効版のHTML(prevarsiNoJsGridHtml)だけで、そこはJSが動くと隠される。
//
// 【失敗したときは付けないこと】エラーの文言は盤面より上に出る。失敗時まで
// 盤面へ送ると、なぜ着手できなかったのかを読まないまま画面だけが動く。
// =====================================================================
// 【対人とコンピュータで目印を分けてあること】JS無効版では、対局中の盤面と
// コンピュータ対局の盤面が**同じページに同時に出ることがある**(対局を開いたまま
// コンピュータとも遊んでいる状態)。同じidを2つ出すと、ブラウザは先に見つけた方へ
// 飛ぶので、コンピュータに一手指したのに対人の盤面へ送られる。1つにまとめないこと。
const PREVARSI_NOJS_BOARD_ANCHOR_MATCH = 'prevarsi-board-match';
const PREVARSI_NOJS_BOARD_ANCHOR_CPU   = 'prevarsi-board-cpu';

/** JS無効時の遷移先に、盤面までの目印を付ける。 */
function prevarsiToBoard(string $url, string $anchor): string {
  return $url . '#' . $anchor;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['reversi_action'] ?? '') === 'toggle_last_move_mark') {
  if (prevarsiCsrfIsValid()) {
    setcookie(LAST_MOVE_MARK_COOKIE, ($_POST['enabled'] ?? '1') === '1' ? '1' : '0', [
      'expires'  => time() + 60 * 60 * 24 * 365,
      'path'     => '/',
      'secure'   => true,
      'httponly' => false, // JS版(game.js)も同じcookieを読み書きするため
      'samesite' => 'Lax',
    ]);
  }
  // 戻り先はPOSTの値をそのまま使わず、既知の画面だけに限定して組み立てる。
  $markReturnView = (string)($_POST['view'] ?? '');
  if ($markReturnView === 'cpu') {
    header('Location: ./?view=cpu');
  } elseif ($markReturnView === 'match') {
    header('Location: ./?view=match&match_id=' . urlencode((string)($_POST['match_id'] ?? '')));
  } else {
    header('Location: ./');
  }
  exit;
}

// 設定画面の「対戦結果の削除」。JSONの?api=系とは別(ページ遷移を伴う
// 通常のPRGフォーム)にしてある。p-chatのdelete_room/set_read_receiptsと同じ考え方。
// p-reversiにはp-chatのpchatFlash相当のセッションフラッシュが無いため、
// 結果はリダイレクト先のクエリパラメータで簡潔に伝える。
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['reversi_action'] ?? '') === 'clear_history') {
  $meForClear = prevarsiCurrentUser();
  if ($meForClear !== null && prevarsiCsrfIsValid()) {
    $deleted = prevarsiClearFinishedMatches($meForClear['userid']);
    header('Location: ./?history_cleared=' . ($deleted !== null ? '1&count=' . $deleted : '0'));
  } else {
    header('Location: ./');
  }
  exit;
}

// =====================================================================
// JavaScript無効環境向けのPMeikeeモード(招待送信・受諾・拒否・着手・投了)。
// 通常のPRGフォームで、判定・保存ロジックそのものは?api=系と同じ
// prevarsiHandleXxx()をそのまま呼ぶ(ロジックは1本化し、ここではJSON化の
// 代わりにLocationヘッダで結果を伝えるだけ)。着手後・投了後・招待受諾後は
// 対局画面(?view=match)へ、招待送信後・招待を断った後はロビーへ戻す。
// =====================================================================
// =====================================================================
// JavaScript無効環境向けのコンピュータ対局(開始・着手・投了)。
// 判定も思考も?api=cpu_*と同じprevarsiHandleCpuXxx()をそのまま呼び、ここでは
// JSON化の代わりにLocationヘッダで結果を伝えるだけにする。アカウント不要
// なので、ログインを要求するPMeikeeモードの処理より前に置いてある。
// =====================================================================
$reversiCpuNoJsActions = ['cpu_new', 'cpu_move', 'cpu_resign'];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && in_array($_POST['reversi_action'] ?? '', $reversiCpuNoJsActions, true)) {
  $cpuAction = (string)$_POST['reversi_action'];
  if ($cpuAction === 'cpu_new') {
    $cpuResult = prevarsiHandleCpuNew();
  } elseif ($cpuAction === 'cpu_move') {
    $cpuResult = prevarsiHandleCpuMove();
  } else {
    $cpuResult = prevarsiHandleCpuResign();
  }

  if (!empty($cpuResult['ok'])) {
    header('Location: ' . prevarsiToBoard('./?view=cpu', PREVARSI_NOJS_BOARD_ANCHOR_CPU));
  } else {
    header('Location: ./?' . http_build_query(['view' => 'cpu', 'reversi_ok' => '0', 'reversi_msg' => $cpuResult['message'] ?? '']));
  }
  exit;
}

$reversiNoJsActions = ['invite', 'accept', 'decline', 'move', 'resign'];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && in_array($_POST['reversi_action'] ?? '', $reversiNoJsActions, true)) {
  $meForAction = prevarsiCurrentUser();
  if ($meForAction === null) {
    header('Location: ./');
    exit;
  }

  $reversiAction = (string)$_POST['reversi_action'];
  $reversiActionMatchId = (string)($_POST['match_id'] ?? '');

  if ($reversiAction === 'invite') {
    $result = prevarsiHandleInvite($meForAction);
    header('Location: ./?' . http_build_query(['reversi_ok' => !empty($result['ok']) ? '1' : '0', 'reversi_msg' => $result['message'] ?? '']));
  } elseif ($reversiAction === 'accept') {
    $result = prevarsiHandleAccept($meForAction);
    if (!empty($result['ok'])) {
      header('Location: ' . prevarsiToBoard('./?view=match&match_id=' . urlencode($reversiActionMatchId), PREVARSI_NOJS_BOARD_ANCHOR_MATCH));
    } else {
      header('Location: ./?' . http_build_query(['reversi_ok' => '0', 'reversi_msg' => $result['message'] ?? '']));
    }
  } elseif ($reversiAction === 'decline') {
    $result = prevarsiHandleDecline($meForAction);
    header('Location: ./?' . http_build_query(['reversi_ok' => !empty($result['ok']) ? '1' : '0', 'reversi_msg' => $result['message'] ?? '']));
  } elseif ($reversiAction === 'move') {
    $result = prevarsiHandleMove($meForAction);
    if (!empty($result['ok'])) {
      header('Location: ' . prevarsiToBoard('./?view=match&match_id=' . urlencode($reversiActionMatchId), PREVARSI_NOJS_BOARD_ANCHOR_MATCH));
    } else {
      header('Location: ./?' . http_build_query(['view' => 'match', 'match_id' => $reversiActionMatchId, 'reversi_ok' => '0', 'reversi_msg' => $result['message'] ?? '']));
    }
  } else {
    $result = prevarsiHandleResign($meForAction);
    if (!empty($result['ok'])) {
      header('Location: ' . prevarsiToBoard('./?view=match&match_id=' . urlencode($reversiActionMatchId), PREVARSI_NOJS_BOARD_ANCHOR_MATCH));
    } else {
      header('Location: ./?' . http_build_query(['view' => 'match', 'match_id' => $reversiActionMatchId, 'reversi_ok' => '0', 'reversi_msg' => $result['message'] ?? '']));
    }
  }
  exit;
}

$currentUser = prevarsiCurrentUser();
$csrfToken = prevarsiCsrfToken();

// 「?view=match&match_id=...」でJS無効環境向けに対局画面を直接開く。
// 判定ロジックは?api=match_stateと同じprevarsiHandleMatchState()を流用する。
$reversiNoJsView = 'lobby';
$reversiNoJsMatch = null;
$reversiNoJsMatchError = null;
if ($currentUser !== null && ($_GET['view'] ?? '') === 'match') {
  $matchStateResult = prevarsiHandleMatchState($currentUser);
  if (!empty($matchStateResult['ok'])) {
    $reversiNoJsView = 'match';
    $reversiNoJsMatch = $matchStateResult['match'];
  } else {
    $reversiNoJsView = 'lobby';
    $reversiNoJsMatchError = $matchStateResult['message'] ?? '対局を開けませんでした。';
  }
}

// コンピュータ対局はログイン不要なので、$currentUserとは無関係にセッション上の
// 対局をそのまま見る。?view=cpuで開いたときだけ盤面を出し、それ以外(初回訪問や
// PMeikeeモードを見に来ただけのとき)は開始フォームを出す。
$reversiNoJsCpuGame = prevarsiCpuGame();
$reversiNoJsCpuOpen = ($_GET['view'] ?? '') === 'cpu' && $reversiNoJsCpuGame !== null;

$title = 'プシューリバーシ - インターネット越しに遊べるオセロ';
$description = 'プシューメイキィのアカウントで相手のユーザー名を指定して対局する、アカウント不要のP2P(WebRTC)で音波の合図だけをたよりに直接つないで対局する、コンピュータと一人で対局する。この3つの方式でオセロ(リバーシ)を遊べます。';

function prevarsiH($value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function prevarsiAssetUrl(string $relativePath): string {
  $absolute = './' . ltrim($relativePath, '/.');
  $version = @filemtime($absolute);
  return $relativePath . ($version !== false ? '?v=' . $version : '');
}

/**
 * 他のプシューサービスへのリンク集(プロダクト一覧)。台帳(pusyuu_registry)から受け取り、
 * 自分(台帳のID p-reversi)は除外して描画する
 * (考え方の転用。見た目はp-reversi既存の.match-listに合わせている)。
 * JSは使わずサーバー側だけで完結する。
 * 【自分を URL で見分けないこと】ドメインや入口が変わると自分が一覧に混ざります。
 */
function prevarsiProductListHtml(): string {
  $links = class_exists('PusyuuRegistryClient') ? PusyuuRegistryClient::links('p-reversi') : null;

  if ($links === null) {
    $html = '<p class="note">プロダクト一覧を読み込めませんでした。</p>';

  } else {
    $rows = '';

    foreach ($links as $product) {
      $url = (string)$product['url'];

      if ($url !== '') {
        $title = prevarsiH((string)($product['title'] ?? $url));
        $image = ($product['image'] !== '') ? $product['image'] : prevarsiSharedAsset('default_avatar');
        $rows .= '
      <li class="match-row">
        <img class="match-row-icon" src="' . prevarsiH($image) . '" alt="" loading="lazy" />
        <div class="match-row-info"><strong>' . $title . '</strong></div>
        <a class="small-button ghost" href="' . prevarsiH($url) . '" target="_blank" rel="noopener noreferrer">開く</a>
      </li>
    ';
      }
    }

    if ($rows === '') {
      $html = '<p class="note">現在、表示できるプロダクトはありません。</p>';
    } else {
      $html = '<ul class="match-list">' . $rows . '</ul>';
    }
  }

  return $html;
}

/** JavaScript無効環境向けの対局ステータス文言(assets/game.js statusLabel()のPHP版)。 */
function prevarsiNoJsStatusLabel(array $m): string {
  if ($m['status'] === 'pending_sent') {
    $label = '招待の返事待ち';
  } else if ($m['status'] === 'pending_received') {
    $label = '招待が届いています';
  } else if ($m['status'] === 'active') {
    $label = $m['my_turn'] ? 'あなたの番です' : '相手の番です';
  } else if ($m['status'] === 'finished' && $m['winner'] === 0) {
    $label = '引き分けで終了';
  } else if ($m['status'] === 'finished') {
    $label = $m['winner'] === $m['my_color'] ? 'あなたの勝ちで終了' : '相手の勝ちで終了';
  } else {
    $label = $m['status'];
  }

  return $label;
}

/** JavaScript無効環境向けの対局一覧(assets/game.js renderList()のPHP版・ページ遷移のみで完結する)。 */
function prevarsiNoJsMatchListHtml(array $matches, string $csrfToken): string {
  if (empty($matches)) {
    $html = '<p class="empty-note">対局はまだありません。相手のユーザー名を指定して招待してみましょう。</p>';
  } else {
  $csrf = prevarsiH($csrfToken);
  $rows = '';
  foreach ($matches as $m) {
    $name = $m['opponent']['name'] !== '' ? $m['opponent']['name'] : $m['opponent']['username'];
    $label = prevarsiH($name);
    $status = prevarsiH(prevarsiNoJsStatusLabel($m));
    $matchId = prevarsiH($m['match_id']);

    $actionsHtml = '';
    if ($m['status'] === 'pending_received') {
      $actionsHtml = '
        <form method="post" action="./" class="inline-form">
          <input type="hidden" name="csrf" value="' . $csrf . '" />
          <input type="hidden" name="reversi_action" value="accept" />
          <input type="hidden" name="match_id" value="' . $matchId . '" />
          <button type="submit" class="small-button">受ける</button>
        </form>
        <form method="post" action="./" class="inline-form">
          <input type="hidden" name="csrf" value="' . $csrf . '" />
          <input type="hidden" name="reversi_action" value="decline" />
          <input type="hidden" name="match_id" value="' . $matchId . '" />
          <button type="submit" class="small-button ghost">断る</button>
        </form>
      ';
    } else if ($m['status'] === 'active' || $m['status'] === 'finished') {
      $actionsHtml = '<a class="small-button" href="./?view=match&amp;match_id=' . $matchId . '">開く</a>';
    }

    $rows .= '
      <li class="match-row">
        <div class="match-row-info"><strong>' . $label . '</strong><span class="match-status">' . $status . '</span></div>
        ' . $actionsHtml . '
      </li>
    ';
  }

    $html = '<ul class="match-list">' . $rows . '</ul>';
  }

  return $html;
}

function prevarsiColorName(int $color): string {
  return $color === O_BLACK ? '黒' : '白';
}

/**
 * JavaScript無効環境向けの盤面グリッド。置けるマスだけを1マス=1フォームの
 * 送信ボタンにし、それ以外はただの<div>で描く。PMeikeeモードとコンピュータ
 * 対局で違うのは送信先のreversi_actionと一緒に送る隠しフィールドだけなので、
 * そこを引数で受け取る形で共有している。
 */
function prevarsiNoJsGridHtml(array $board, array $legalSet, ?array $lastMove, string $action, array $hiddenFields, string $csrfToken, string $anchorId): string {
  $csrf = prevarsiH($csrfToken);
  $hiddenHtml = '';
  foreach ($hiddenFields as $name => $value) {
    $hiddenHtml .= '<input type="hidden" name="' . prevarsiH($name) . '" value="' . prevarsiH($value) . '" />';
  }

  $lastRow = $lastMove !== null ? (int)($lastMove['row'] ?? $lastMove[0] ?? -1) : -1;
  $lastCol = $lastMove !== null ? (int)($lastMove['col'] ?? $lastMove[1] ?? -1) : -1;

  $cellsHtml = '';
  for ($r = 0; $r < 8; $r++) {
    for ($c = 0; $c < 8; $c++) {
      $v = $board[$r][$c];
      $cls = 'cell';
      if ($v === O_BLACK) {
        $cls .= ' black';
      } elseif ($v === O_WHITE) {
        $cls .= ' white';
      }
      $label = ($r + 1) . '行' . ($c + 1) . '列';
      if ($r === $lastRow && $c === $lastCol) {
        $cls .= ' last-move';
        $label .= '(直前に置かれた石)';
      }
      if (isset($legalSet[$r . '_' . $c])) {
        $cellsHtml .= '
          <form method="post" action="./" class="cell-form">
            <input type="hidden" name="csrf" value="' . $csrf . '" />
            <input type="hidden" name="reversi_action" value="' . prevarsiH($action) . '" />
            ' . $hiddenHtml . '
            <input type="hidden" name="row" value="' . $r . '" />
            <input type="hidden" name="col" value="' . $c . '" />
            <button type="submit" class="' . $cls . ' legal" aria-label="' . $label . 'に置く"></button>
          </form>
        ';
      } else {
        $cellsHtml .= '<div class="' . $cls . '" aria-label="' . $label . '"></div>';
      }
    }
  }
  // 【目印は盤面そのものではなく、その手前の空要素に付けること】
  // ヘッダーは position:sticky で画面の上に貼り付いたままなので、盤面の先頭を
  // そのまま指すと、盤面の上側がヘッダーの下へ潜って隠れる。石を置くために
  // 今度は上へスクロールし直すことになり、直したい問題が形を変えて残る。
  //
  // そこで高さ0の空要素を盤面の手前に置き、position:relative の top を
  // マイナスにしてヘッダーの高さぶん上へずらしておく。ブラウザはずらした後の
  // 位置へ送るので、盤面はヘッダーの下からきちんと見える。空要素は高さ0のまま
  // 流れの中に残るので、見た目の間隔は変わらない。
  //
  // scroll-margin-top という新しい書き方でも同じことができるが、JS無効で
  // 使われるような古いブラウザには無い。ここは一番効いてほしい相手がその古い側
  // なので、どこでも効くこちらで書いている(assets/style.css の .board-anchor)。
  return '<span class="board-anchor" id="' . prevarsiH($anchorId) . '" aria-hidden="true"></span>'
    . '<div class="board-container"><div class="othello-grid">' . $cellsHtml . '</div></div>';
}

/** 「あなたの番のときに置けるマス」の集合。行_列 をキーにした連想配列で返す。 */
function prevarsiNoJsLegalSet(array $board, bool $isMyTurn, int $myColor): array {
  $legalSet = [];

  // 自分の番でなければ、置けるマスは1つも出しません(相手の番に押せてしまわないように)。
  if ($isMyTurn) {
    foreach (othelloLegalMoves($board, $myColor) as [$lr, $lc]) {
      $legalSet[$lr . '_' . $lc] = true;
    }
  }

  return $legalSet;
}

/** JavaScript無効環境向けの対局画面(assets/game.js renderMatch()のPHP版)。1マス=1フォームの着手ボタン。 */
function prevarsiNoJsBoardHtml(array $match, string $csrfToken): string {
  $board = $match['board'];
  $matchId = prevarsiH($match['match_id']);
  $csrf = prevarsiH($csrfToken);

  $legalSet = prevarsiNoJsLegalSet($board, $match['status'] === 'active' && $match['my_turn'], $match['my_color']);
  $gridHtml = prevarsiNoJsGridHtml($board, $legalSet, prevarsiShowLastMoveMark() ? ($match['last_move'] ?? null) : null, 'move', ['match_id' => $match['match_id']], $csrfToken, PREVARSI_NOJS_BOARD_ANCHOR_MATCH);

  $score = othelloScore($board);
  $scoreLabel = '黒 ' . $score['black'] . ' - 白 ' . $score['white'];
  if ($match['my_color'] === O_BLACK) {
    $myName = $match['black']['name'];
    $opponentColor = O_WHITE;
    $opponentName = $match['white']['name'];
  } else {
    $myName = $match['white']['name'];
    $opponentColor = O_BLACK;
    $opponentName = $match['black']['name'];
  }
  $playersLabel = 'あなた: ' . prevarsiColorName($match['my_color']) . '(' . prevarsiH($myName) . ') / ' .
    '相手: ' . prevarsiColorName($opponentColor) . '(' . prevarsiH($opponentName) . ')';

  if ($match['status'] === 'finished') {
    if ($match['winner'] === 0) {
      $statusLabel = '引き分けです。';
    } elseif ($match['winner'] === $match['my_color']) {
      $statusLabel = 'あなたの勝ちです!';
    } else {
      $statusLabel = '相手の勝ちです。';
    }
  } elseif ($match['my_turn']) {
    $statusLabel = 'あなたの番です。石を置けるマスのボタンで着手できます。';
  } else {
    $statusLabel = '相手の番です。しばらくお待ちください。下の「盤面を最新にする」で今の状態を確認できます。';
  }

  // 相手の手番のあいだ、状態を取り直すためのリンク。
  // 【ページの再読み込みで済ませないこと】再読み込みだと表示がページの一番上へ戻り、
  // 盤面を見るのに毎回スクロールし直すことになります。行き先に盤面までの目印を
  // 付けてあるので、押しても盤面が画面に残ります(PREVARSI_NOJS_BOARD_ANCHOR参照)。
  $refreshHtml = '';
  if ($match['status'] === 'active') {
    $refreshHtml = '<a class="small-button ghost" href="' .
      prevarsiH(prevarsiToBoard('./?view=match&match_id=' . urlencode($match['match_id']), PREVARSI_NOJS_BOARD_ANCHOR_MATCH)) .
      '">盤面を最新にする</a>';
  }

  $resignHtml = '';
  if ($match['status'] === 'active') {
    $resignHtml = '
      <form method="post" action="./">
        <input type="hidden" name="csrf" value="' . $csrf . '" />
        <input type="hidden" name="reversi_action" value="resign" />
        <input type="hidden" name="match_id" value="' . $matchId . '" />
        <button type="submit" class="small-button ghost">投了する</button>
      </form>
    ';
  }

  return '
    <a class="small-button ghost" href="./">← ロビーに戻る</a>
    <p class="players-label">' . $playersLabel . '</p>
    <p class="score-label">' . prevarsiH($scoreLabel) . '</p>
    ' . $gridHtml . '
    <p class="status-label">' . prevarsiH($statusLabel) . '</p>
    ' . $refreshHtml . '
    ' . $resignHtml . '
  ';
}

/** コンピュータ対局の強さを選ぶ<select>。JS版とJS無効版の両方から使う。 */
function prevarsiCpuLevelSelectHtml(string $id, string $name, int $selected): string {
  $options = '';
  foreach (PREVARSI_CPU_LEVEL_LABELS as $level => $label) {
    $options .= '<option value="' . (int)$level . '"' . ($level === $selected ? ' selected' : '') . '>' . prevarsiH($label) . '</option>';
  }
  return '<select id="' . prevarsiH($id) . '" name="' . prevarsiH($name) . '">' . $options . '</select>';
}

/** JavaScript無効環境向けのコンピュータ対局画面。着手の送信先以外はPMeikee版と同じ作り。 */
function prevarsiNoJsCpuBoardHtml(array $game, string $csrfToken): string {
  $view = prevarsiCpuViewState($game);
  $board = $view['board'];
  $csrf = prevarsiH($csrfToken);

  $legalSet = prevarsiNoJsLegalSet($board, $view['status'] === 'active' && $view['my_turn'], $view['my_color']);
  $gridHtml = prevarsiNoJsGridHtml($board, $legalSet, prevarsiShowLastMoveMark() ? $view['last_move'] : null, 'cpu_move', [], $csrfToken, PREVARSI_NOJS_BOARD_ANCHOR_CPU);

  $cpuColor = othelloOpponent($view['my_color']);
  $levelLabel = PREVARSI_CPU_LEVEL_LABELS[$view['level']] ?? ('レベル' . $view['level']);
  $playersLabel = 'あなた: ' . prevarsiColorName($view['my_color']) . ' / ' .
    'コンピュータ: ' . prevarsiColorName($cpuColor) . '(' . prevarsiH($levelLabel) . ')';
  $scoreLabel = '黒 ' . $view['score']['black'] . ' - 白 ' . $view['score']['white'];

  if ($view['status'] === 'finished') {
    if ($view['winner'] === 0) {
      $statusLabel = '引き分けです。';
    } elseif ($view['winner'] === $view['my_color']) {
      $statusLabel = 'あなたの勝ちです!';
    } else {
      $statusLabel = 'コンピュータの勝ちです。';
    }
  } elseif ($view['my_turn']) {
    $statusLabel = 'あなたの番です。石を置けるマスのボタンで着手できます。';
  } else {
    $statusLabel = 'コンピュータが考えています。';
  }

  if ($view['passed'] === 'cpu') {
    $statusLabel = 'コンピュータは置ける場所が無いためパスになりました。' . $statusLabel;
  } elseif ($view['passed'] === 'you') {
    $statusLabel = 'あなたは置ける場所が無いためパスになりました。' . $statusLabel;
  }

  $resignHtml = '';
  if ($view['status'] === 'active') {
    $resignHtml = '
      <form method="post" action="./">
        <input type="hidden" name="csrf" value="' . $csrf . '" />
        <input type="hidden" name="reversi_action" value="cpu_resign" />
        <button type="submit" class="small-button ghost">投了する</button>
      </form>
    ';
  }

  return '
    <p class="players-label">' . prevarsiH($playersLabel) . '</p>
    <p class="score-label">' . prevarsiH($scoreLabel) . '</p>
    ' . $gridHtml . '
    <p class="status-label">' . prevarsiH($statusLabel) . '</p>
    ' . $resignHtml . '
    <form method="post" action="./">
      <input type="hidden" name="csrf" value="' . $csrf . '" />
      <input type="hidden" name="reversi_action" value="cpu_new" />
      <input type="hidden" name="level" value="' . (int)$view['level'] . '" />
      <input type="hidden" name="my_color" value="' . ($view['my_color'] === O_BLACK ? 'black' : 'white') . '" />
      <button type="submit" class="small-button">同じ設定でもう一局</button>
    </form>
    <a class="small-button ghost" href="./">設定を変えて始め直す</a>
  ';
}

/**
 * ブラウザ側(assets/game.js)へ渡す初期データ。
 *
 * JavaScriptが動くときにしか使わないUI(対局方式のタブ・P2P対局の画面・
 * その場で更新される盤面)は、このファイルからは一切出力しない。必要な材料
 * ——ログイン状態・CSRFトークン・アカウント画面のURL・コンピュータの強さの
 * 選択肢——をここでまとめて渡し、DOMはすべてgame.jsが組み立てる。
 * そのため<noscript>でJS専用のHTMLを隠す必要も無くなっている。
 */
$cpuLevelOptions = [];
foreach (PREVARSI_CPU_LEVEL_LABELS as $cpuLevelValue => $cpuLevelLabel) {
  $cpuLevelOptions[] = ['value' => (int)$cpuLevelValue, 'label' => $cpuLevelLabel];
}

$bootstrap = [
  'user' => $currentUser,
  'csrf' => $csrfToken,
  'accounts' => [
    'login'  => PrevarsiAccount::loginUrl(),
    'create' => PrevarsiAccount::createUrl(),
  ],
  'cpu' => [
    'levels'       => $cpuLevelOptions,
    'defaultLevel' => PREVARSI_CPU_DEFAULT_LEVEL,
  ],
  // P2PのICE設定。空なら同じネットワーク内の相手としかつながらないので、
  // ブラウザ側はその前提で案内文とエラー文言を出し分ける。
  'p2p' => [
    'iceServers'   => prevarsiIceServers(),
    'crossNetwork' => prevarsiIceServers() !== [],
  ],
  // JS無効版のリンク(?view=...)から来た場合に、同じ画面をJS版でも開くための手がかり。
  'view' => [
    'name'    => (string)($_GET['view'] ?? ''),
    'matchId' => (string)($_GET['match_id'] ?? ''),
  ],
  'lastMoveMarkCookie' => LAST_MOVE_MARK_COOKIE,
];

$noJsMessage = isset($_GET['reversi_msg']) ? (string)$_GET['reversi_msg'] : '';
$noJsMessageIsError = ($_GET['reversi_ok'] ?? '1') === '0';
$noJsMessageView = (string)($_GET['view'] ?? '');
?>
<!DOCTYPE html>
<html lang="ja">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, user-scalable=yes, maximum-scale=6.0, minimum-scale=1.0" />
    <meta name="copyright" content="© 2026 created by PusyuuWanko/" />
    <meta name="description" content="<?php echo prevarsiH($description); ?>" />
    <meta name="keywords" content="プシューサービス,PusyuuService,プシューリバーシ,オセロ,リバーシ,Othello,Reversi,オンライン対戦,一人用,コンピュータ対戦,CPU対戦,P2P,WebRTC,音波,quiet.js,プシューメイキィ" />
    <meta name="theme-color" content="#04120f" />
    <meta property="og:title" content="<?php echo prevarsiH($title); ?>" />
    <meta property="og:site_name" content="<?php echo prevarsiH($title); ?>" />
    <meta property="og:description" content="<?php echo prevarsiH($description); ?>" />
    <meta property="og:image" content="<?php echo prevarsiH(prevarsiSharedAsset('og_image')); ?>" />
    <meta property="og:type" content="application" />
    <title><?php echo prevarsiH($title); ?></title>
    <link rel="shortcut icon" href="<?php echo prevarsiH(prevarsiSharedAsset('favicon')); ?>" />
    <link href="<?php echo prevarsiH(prevarsiAssetUrl('./assets/style.css')); ?>" rel="stylesheet" />
    <script id="prevarsi-bootstrap" type="application/json"><?php echo json_encode($bootstrap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?></script>
    <!-- privacy_notice.js の script タグより前に置く -->
    <script>
      window.PusyuuPrivacyNoticeConfig = {
        title: "プライバシーに関するお知らせ",
        serviceName: "プシューリバーシ",
        closeButtonText: "同意する",
        hostsIntroText: "このページ読み込み時に検出した外部の通信先：",
        hostsNoneText: "このページ読み込み時には、外部への通信は検出されませんでした。",
        storageKey: "pusyuu_privacy_notice_dismissed",
        jsDependency: {
          level: "medium",
          brokenFeatures: ["P2P対局(WebRTC)", "音波での合図コードの送受信", "対局の自動更新・通知音", "対局方式のタブ切り替え"],
          fallbackNote:
            'プシューメイキィにログインしての対局と、コンピュータとの一人対局(いずれも招待・受諾・着手・投了を含む)はJavaScriptが無くてもご利用いただけます。詳しい方針は' +
            '<a href="<?= prevarsiSiteBase('main') ?>/rules/javascript/" target="_blank">JavaScriptの取り扱いに関する方針</a>をご覧ください。'
        },
        triggerCloseButtonText: "閉じる"
      };
    </script>
    <script src="<?php echo prevarsiH(prevarsiSharedAsset('privacy_notice')); ?>"></script>
    <script src="<?php echo prevarsiH(prevarsiSharedAsset('request_indicator')); ?>" data-include-async="false"></script>
    <!--
        *----------------------------------
        |  © 2021-2026 By ISAMI ABE     |
        |  LastUpdate: 2026-09-20       |
        |  License: MIT License         |
        |  PusyuuReversi....           |
      ----------------------------------*
    -->
  </head>
  <body>
    <div class="backdrop" aria-hidden="true">
      <div class="backdrop-grid"></div>
      <div class="backdrop-glow"></div>
      <div class="backdrop-scan"></div>
    </div>

    <header class="site-header">
      <a class="site-title" href="./">
        <span class="site-title-mark" aria-hidden="true"></span>
        <span class="site-title-text">プシューリバーシ</span>
      </a>
      <nav class="site-nav">
<?php if ($currentUser !== null): ?>
        <span class="site-user"><?php echo prevarsiH($currentUser['name']); ?></span>
        <form method="post" action="./" class="nav_form">
          <input type="hidden" name="csrf" value="<?php echo prevarsiH($csrfToken); ?>" />
          <input type="hidden" name="account_action" value="logout" />
          <button type="submit" class="link_button" title="ログアウト">ログアウト</button>
        </form>
<?php elseif (PrevarsiAccount::ready()): ?>
        <a class="link_button" href="<?php echo prevarsiH(PrevarsiAccount::loginUrl()); ?>" title="プシューメイキィでログイン">ログイン</a>
<?php endif; ?>
      </nav>
    </header>

    <main class="site-main">
      <p class="lead">プシューメイキィのアカウントで相手を指定して対局する、アカウント無しでP2P(WebRTC)で直接つないで対局する、コンピュータと一人で対局する。この3つの方式でオセロを遊べます。</p>

      <!-- JavaScriptが動くと、game.jsがこの中に対局方式のタブと各対局画面を
           組み立てて表示し、下の #prevarsi-nojs を隠す。JSでしか動かないUIの
           HTMLはサーバー側では一切出力していないので、ここは常に空で始まる。 -->
      <div id="prevarsi-app"></div>

      <div id="prevarsi-nojs">
        <p class="note js-notice">
          JavaScriptが無効な環境向けの表示です。P2P対局(WebRTC)と音波での合図はJavaScriptからしか操作できないため利用できません。
          プシューメイキィにログインしての対局と、コンピュータとの一人対局(着手・投了を含む)はこのままご利用いただけます。
        </p>

<?php if ($noJsMessage !== ''): ?>
        <p class="form-message <?php echo $noJsMessageIsError ? 'error' : 'ok'; ?>"><?php echo prevarsiH($noJsMessage); ?></p>
<?php endif; ?>

        <section class="panel">
          <h2 class="panel-title">プシューメイキィで対戦</h2>
<?php if ($currentUser === null && !PrevarsiAccount::ready()): ?>
          <?php /* 押しても行き止まりになるリンクは出さず、理由だけを書きます。
                   p-reversiはログインしなくても遊べるので、その旨も添えます。
                   ここはPHPが書き出すHTMLなので、JavaScriptが無くても読めます。 */ ?>
          <p>いまプシューメイキィと連携できないため、ログインできません。</p>
          <p class="note"><?php echo prevarsiH(PrevarsiAccount::unavailableReason()); ?></p>
          <p class="note">CPU戦や同じ端末での対戦は、これまでどおりお楽しみいただけます。</p>
<?php elseif ($currentUser === null): ?>
          <p>プシューメイキィにログインすると、相手のユーザー名を指定して対局を招待できます。</p>
          <p>
            <a href="<?php echo prevarsiH(PrevarsiAccount::loginUrl()); ?>" class="button">ログイン</a>
          </p>
          <p class="note">
            プシューメイキィをお持ちでない場合は
            <a href="<?php echo prevarsiH(PrevarsiAccount::createUrl()); ?>">こちらで作成</a>
            してください。
          </p>
<?php else: ?>
          <p class="note"><?php echo prevarsiH($currentUser['name']); ?>さん(<?php echo prevarsiH($currentUser['username']); ?>)としてログイン中です。</p>

<?php if ($reversiNoJsMatchError !== null): ?>
          <p class="form-message error"><?php echo prevarsiH($reversiNoJsMatchError); ?></p>
<?php endif; ?>

<?php if ($reversiNoJsView === 'match' && $reversiNoJsMatch !== null): ?>
          <?php echo prevarsiNoJsBoardHtml($reversiNoJsMatch, $csrfToken); ?>
<?php else: ?>
          <form method="post" action="./" class="invite-form">
            <label for="nojs-invite-username">対戦相手のユーザー名</label>
            <input type="text" id="nojs-invite-username" name="opponent_username" autocomplete="off" required />
            <input type="hidden" name="csrf" value="<?php echo prevarsiH($csrfToken); ?>" />
            <input type="hidden" name="reversi_action" value="invite" />
            <button type="submit">招待する</button>
          </form>

          <h3 class="section-title">対局一覧</h3>
          <?php echo prevarsiNoJsMatchListHtml(prevarsiHandleList($currentUser)['matches'] ?? [], $csrfToken); ?>
<?php endif; ?>
<?php endif; ?>
        </section>

        <section class="panel">
          <h2 class="panel-title">コンピュータと対戦</h2>
<?php if ($reversiNoJsCpuOpen): ?>
          <?php echo prevarsiNoJsCpuBoardHtml($reversiNoJsCpuGame, $csrfToken); ?>
<?php else: ?>
          <p class="note">ログインも対戦相手も要りません。盤面の判定も次の一手の思考もすべてサーバー側で行います。</p>
          <form method="post" action="./" class="invite-form">
            <input type="hidden" name="csrf" value="<?php echo prevarsiH($csrfToken); ?>" />
            <input type="hidden" name="reversi_action" value="cpu_new" />
            <label for="nojs-cpu-level">コンピュータの強さ</label>
            <?php echo prevarsiCpuLevelSelectHtml('nojs-cpu-level', 'level', PREVARSI_CPU_DEFAULT_LEVEL); ?>
            <label for="nojs-cpu-color">あなたの石</label>
            <select id="nojs-cpu-color" name="my_color">
              <option value="black">黒(先手)</option>
              <option value="white">白(後手)</option>
              <option value="random">おまかせ</option>
            </select>
            <button type="submit">対局を始める</button>
          </form>
<?php if ($reversiNoJsCpuGame !== null): ?>
          <p class="note"><a href="./?view=cpu">前回の対局の続きを開く</a></p>
<?php endif; ?>
<?php endif; ?>
        </section>
      </div>

      <!-- 設定はJavaScriptの有無に関わらず意味があるので常にここに出力し、
           JSが動いたときはgame.jsがこの要素ごと「設定」タブの中へ移動させて
           再利用する(同じUIを2つ持たないため)。 -->
      <section class="panel" id="prevarsi-settings">
        <h2 class="panel-title">設定</h2>

<?php if (isset($_GET['history_cleared'])): ?>
        <p class="form-message <?php echo $_GET['history_cleared'] === '1' ? 'ok' : 'error'; ?>">
<?php if ($_GET['history_cleared'] === '1'): ?>
          対戦結果を<?php echo (int)($_GET['count'] ?? 0); ?>件削除しました。
<?php else: ?>
          対戦結果の削除に失敗しました。
<?php endif; ?>
        </p>
<?php endif; ?>

        <!-- JS版では、game.jsがこの直前に音の設定と共通の切り替えスイッチを差し込む。
             ここのフォームはJS無効環境用で、JSが動いたときは隠される。 -->
        <div class="settings-item" id="prevarsi-mark-setting-nojs">
          <h3 class="section-title">直前に置かれた石の印</h3>
          <p class="note">相手がどこに置いたのかが分かるよう、いちばん最後に置かれた石に印をつけます。</p>
          <form method="post" action="./" class="inline-form">
            <input type="hidden" name="csrf" value="<?php echo prevarsiH($csrfToken); ?>" />
            <input type="hidden" name="reversi_action" value="toggle_last_move_mark" />
            <input type="hidden" name="enabled" value="<?php echo prevarsiShowLastMoveMark() ? '0' : '1'; ?>" />
            <input type="hidden" name="view" value="<?php echo prevarsiH($noJsMessageView); ?>" />
            <input type="hidden" name="match_id" value="<?php echo prevarsiH((string)($_GET['match_id'] ?? '')); ?>" />
            <button type="submit" class="small-button ghost">
              <?php echo prevarsiShowLastMoveMark() ? '印を表示しない' : '印を表示する'; ?>
            </button>
          </form>
        </div>

<?php if ($currentUser !== null): ?>
        <div class="settings-item">
          <h3 class="section-title">アカウント</h3>
          <p class="note">名前やユーザー名などのプロフィールは、プシューメイキィ側で編集します。</p>
          <a class="small-button" href="<?php echo prevarsiH(PrevarsiAccount::manageUrl()); ?>">メイキーで編集する</a>
        </div>

        <div class="settings-item">
          <h3 class="section-title">対戦結果の削除</h3>
          <p class="note">終了済みの対局記録をまとめて削除します(進行中・招待中の対局は残ります)。<strong>元に戻せません</strong>。</p>
          <form method="post" action="./" onsubmit="return confirm('終了済みの対戦結果をすべて削除しますか?元に戻せません。');">
            <input type="hidden" name="csrf" value="<?php echo prevarsiH($csrfToken); ?>" />
            <input type="hidden" name="reversi_action" value="clear_history" />
            <button type="submit" class="small-button ghost danger">終了済みの対戦結果を削除する</button>
          </form>
        </div>
<?php endif; ?>

        <div class="settings-item">
          <h3 class="section-title">プロダクト一覧</h3>
          <p class="note">その他のプシューサービスへのリンクです。</p>
          <?php echo prevarsiProductListHtml(); ?>
        </div>
      </section>
    </main>

    <footer class="site-footer">
      <div><?php echo function_exists('notationDisp') ? notationDisp('notation') : ''; ?></div>
      <div><?php echo function_exists('notationDisp') ? notationDisp('copyright') : ''; ?></div>
    </footer>

    <script src="<?php echo prevarsiH(prevarsiSharedAsset('notify_sound')); ?>"></script>
    <!-- 音波(quiet.js)一式。読み込み順はp-chatと同じで、quiet.jsを使う側
         (sonic.js・game.js)より前にquiet.js本体を、実体のwasm/asm.jsに当たる
         quiet-emscripten.jsは最後にasyncで置く(Quiet.init()の呼び出しが
         そのフェッチ完了より先に済んでいる必要があるため。詳しくは
         assets/sonic.js冒頭のコメントを参照)。 -->
    <script src="<?php echo prevarsiH(prevarsiAssetUrl('./assets/audio-profiles.js')); ?>"></script>
    <script src="<?php echo prevarsiH(prevarsiAssetUrl('./assets/vendor/quiet/quiet.js')); ?>"></script>
    <script src="<?php echo prevarsiH(prevarsiAssetUrl('./assets/sonic.js')); ?>"></script>
    <script src="<?php echo prevarsiH(prevarsiAssetUrl('./assets/game.js')); ?>"></script>
    <script async src="<?php echo prevarsiH(prevarsiAssetUrl('./assets/vendor/quiet/quiet-emscripten.js')); ?>"></script>
    <script src="<?php echo prevarsiH(prevarsiSharedAsset('touch_tooltip')); ?>"></script>
  </body>
</html>
