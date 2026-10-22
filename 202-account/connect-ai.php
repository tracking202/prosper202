<?php

declare(strict_types=1);

include_once(str_repeat("../", 1) . '202-config/connect.php');
require_once __DIR__ . '/../202-config/functions-account-ui.php';

use Api\V3\Support\AiConnect;
use Api\V3\Support\ServerStateStore;
use Prosper202\Click\TrackingBaseUrl;

/*
 * Connect an AI assistant: the approval step of Tracking202's MCP server.
 *
 * An AI app (Claude, ...) sends the person here from mcp.tracking202.com.
 * Signed in as themselves, they choose what the assistant may do, and on
 * Allow this install gives the MCP server a one-time code at its fixed
 * address (AiConnect::redirectUri()), never at an address the link names.
 * The MCP server exchanges the code for a new API key of this user
 * (POST /api/v3/connect/token), so the user's own role decides what the
 * assistant can do. Minting a key needs access_to_personal_settings, as on
 * Account › API keys.
 */

AUTH::require_user();

// A consent page must not be framed: a click on Allow is the decision.
header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'");
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$request = AiConnect::readRequest($isPost ? $_POST : $_GET);
$userName = (string) ($_SESSION['user_name'] ?? '');
$error = '';
// This install as the person's browser reached it. Only their browser chose
// the host, so it is what the MCP server must have been told (and is told
// back, as iss), or another install sent them here.
$self = TrackingBaseUrl::forRequest($_SERVER);
if (is_array($request) && !AiConnect::sameInstall($request['install'], $self)) {
    $request = 'This approval link was made for ' . $request['install'] . ', not for this install (' . $self
        . '), so nothing can be approved here. If you did not expect to be sent here, close this page.';
}

if (is_string($request)) {
    $error = $request;
} elseif (!$userObj->hasPermission('access_to_personal_settings')) {
    $error = 'Connecting an AI assistant creates an API key, which your role cannot do. '
        . 'Ask an administrator of this Prosper202 to connect it, or to give you that permission.';
} elseif ($isPost) {
    if (!AUTH::check_csrf_token()) {
        $error = P202_ACCOUNT_TOKEN_REFUSED;
    } else {
        $decision = (string) ($_POST['decision'] ?? '');
        $query = ['state' => $request['state'], 'iss' => $self];
        if ($decision === 'allow') {
            $scope = AiConnect::scopeFor((string) ($_POST['access'] ?? ''));
            if ($scope === null) {
                $error = 'Choose what the assistant may do.';
            } else {
                $userId = (int) $_SESSION['user_own_id'];
                $store = new ServerStateStore();
                $challenge = $request['code_challenge'];
                $code = AiConnect::approve($store, $userId, $scope, $challenge, $request['client_name']);
                $query = ['code' => $code] + $query;
            }
        } elseif ($decision === 'deny') {
            $query = ['error' => 'access_denied'] + $query;
        } else {
            $error = 'Choose Allow or Cancel.';
        }
        if ($error === '') {
            header('Location: ' . AiConnect::redirectUri() . '?' . http_build_query($query), true, 303);
            exit;
        }
    }
}

info_top(['title' => 'Connect an AI assistant - Prosper202 ClickServer']);

if (is_string($request)) {
    echo p202_standalone_card('This link cannot be used', $error);
    echo p202_standalone_card_end();
    info_bottom();
    exit;
}

$relayHost = (string) parse_url(AiConnect::relayOrigin(), PHP_URL_HOST);
// The three accesses, in the order shown; AiConnect::DEFAULT_ACCESS is checked.
$accessChoices = [
    'read' => [
        'Read only <span class="text-secondary">(recommended)</span>',
        'It reads your campaigns, trackers, landing pages, traffic sources, rotators, reports, clicks and '
            . 'conversions, as far as your role can, and changes nothing.',
    ],
    'propose' => [
        'Read, and propose changes',
        'It reads the same. A change it makes to campaigns, trackers, landing pages, traffic sources or '
            . 'categories waits as a proposal until you apply it with the p202 command line '
            . '(<code>p202 change apply</code>).',
    ],
    'write' => [
        'Read and change',
        'It reads the same, and can change and delete campaigns, trackers, landing pages, traffic sources and '
            . 'categories; deleting a tracker stops its links. Your AI app\'s approval prompt is then the only '
            . 'check, so do not set it to always allow deletes.',
    ],
];
echo p202_standalone_card('Connect an AI assistant');
?>
    <p>
        Tracking202's MCP server (<?php echo $e($relayHost); ?>) says that
        <?php if ($request['client_domain'] !== '') { ?>
        <strong><?php echo $e($request['client_name']); ?></strong>, verified there as
        <strong><?php echo $e($request['client_domain']); ?></strong>,
        <?php } else { ?>
        an app calling itself <strong>&ldquo;<?php echo $e($request['client_name']); ?>&rdquo;</strong>
        (a name it chose, not verified)
        <?php } ?>
        asks to use this Prosper202 as <strong><?php echo $e($userName); ?></strong>, and that the connection
        is handed to <strong><?php echo $e($request['redirect_host']); ?></strong>.
    </p>
    <p class="small text-secondary">
        Allow it only if you started connecting this app yourself, just now, in this browser: the connection
        completes only in the browser that started it. Whatever you choose, it cannot see or change users,
        API keys or this install's settings.
    </p>
<?php if ($error !== '') { ?>
    <div class="alert alert-danger p202-flash" role="alert">
        <i class="bi bi-x-circle"></i><div class="p202-flash__body"><?php echo $e($error); ?></div>
    </div>
<?php }
if ($userObj->hasPermission('access_to_personal_settings')) { ?>
    <form method="post" action="">
        <?php echo p202_account_token_field(); ?>
        <input type="hidden" name="state" value="<?php echo $e($request['state']); ?>">
        <input type="hidden" name="code_challenge" value="<?php echo $e($request['code_challenge']); ?>">
        <input type="hidden" name="code_challenge_method" value="S256">
        <input type="hidden" name="client_name" value="<?php echo $e($request['client_name']); ?>">
        <input type="hidden" name="client_domain" value="<?php echo $e($request['client_domain']); ?>">
        <input type="hidden" name="redirect_host" value="<?php echo $e($request['redirect_host']); ?>">
        <input type="hidden" name="install" value="<?php echo $e($request['install']); ?>">
        <input type="hidden" name="redirect_uri" value="<?php echo $e(AiConnect::redirectUri()); ?>">
        <fieldset class="mb-3">
            <legend class="form-label fs-6 fw-semibold">What may it do?</legend>
            <?php foreach ($accessChoices as $access => [$accessLabel, $accessHelp]) {
                $checked = $access === AiConnect::DEFAULT_ACCESS ? ' checked' : ''; ?>
            <div class="form-check mb-2">
                <input class="form-check-input" type="radio" name="access" id="access-<?php echo $access; ?>"
                    value="<?php echo $access; ?>"<?php echo $checked; ?>>
                <label class="form-check-label" for="access-<?php echo $access; ?>"><?php echo $accessLabel; ?></label>
                <div class="form-text"><?php echo $accessHelp; ?></div>
            </div>
            <?php } ?>
        </fieldset>
        <p class="small text-secondary">
            This creates an API key for you, shown in
            My Account › Personal Settings › API keys. Remove it there to disconnect;
            connecting the app again creates another.
        </p>
        <p class="small text-secondary">
            To improve the connector, Tracking202's MCP server records which tools the app calls, the names
            (not the values) of what it passes them, whether they work, with the error when one fails, and the
            app's one-line description of what each request is for, which can repeat what you asked. Email
            addresses, web addresses and long numbers are removed first. The records are kept with this
            install's ClickServer licence for up to two years, so Tracking202 sees them for your account. It
            does not record what the tools return.
        </p>
        <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit" name="decision" value="allow">Allow</button>
            <button class="btn btn-outline-secondary" type="submit" name="decision" value="deny">Cancel</button>
        </div>
    </form>
<?php }
echo p202_standalone_card_end();
info_bottom();
