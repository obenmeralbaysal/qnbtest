<?php
declare(strict_types=1);

require __DIR__ . '/lib/qnb.php';

qnb_session_start();

$defaults = [
    'mode' => '3dpay',
    'env' => 'test',
    'mbrId' => '5',
    'merchantId' => '',
    'merchantPass' => '',
    'userCode' => '',
    'userPass' => '',
    'orderId' => 'TEST' . date('YmdHis'),
    'purchAmount' => '1',
    'installmentCount' => '0',
    'currency' => '949',
    'txnType' => 'Auth',
    'pan' => '',
    'expiry' => '',
    'cvv' => '',
];

$values = $defaults;
$errors = [];
$direct = null;
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

if (!$isPost) {
    $values = array_merge($values, qnb_demo('3dpay'));
} else {
    foreach ($defaults as $key => $unused) {
        if (isset($_POST[$key]) && is_string($_POST[$key])) {
            $values[$key] = trim($_POST[$key]);
        }
    }

    $mode = $values['mode'];
    $env = $values['env'];
    $amount = str_replace(',', '.', $values['purchAmount']);
    $values['purchAmount'] = $amount;
    $pan = preg_replace('/\s+/', '', $values['pan']) ?? '';
    $values['pan'] = $pan;

    if (!in_array($mode, ['3dpay', '3dhost'], true)) {
        $errors[] = 'Model 3DPay veya 3DHost olmalı.';
    }
    if (!in_array($env, ['test', 'live'], true)) {
        $errors[] = 'Ortam test veya canlı olmalı.';
    }
    if (!preg_match('/^\d{1,4}$/', $values['mbrId'])) {
        $errors[] = 'Kurum kodu (MbrId) sayısal olmalı.';
    }
    if ($values['merchantId'] === '' || strlen($values['merchantId']) > 24) {
        $errors[] = 'Üye işyeri numarası gerekli.';
    }
    if ($values['merchantPass'] === '') {
        $errors[] = '3D şifresi (MerchantPass) gerekli.';
    }
    if ($values['userCode'] === '' || $values['userPass'] === '') {
        $errors[] = 'Api kullanıcı kodu ve şifresi gerekli.';
    }
    if (!preg_match('/^[A-Za-z0-9_-]{1,50}$/', $values['orderId'])) {
        $errors[] = 'Sipariş numarası 1-50 karakter, harf, rakam, _ veya - olmalı.';
    }
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $amount) || (float) $amount <= 0) {
        $errors[] = 'Tutar 0’dan büyük olmalı. Örnek: 1 veya 10.50';
    }
    if (!preg_match('/^\d{1,2}$/', $values['installmentCount'])) {
        $errors[] = 'Taksit sayısı 0 (peşin) ile 99 arasında olmalı.';
    }
    if (!preg_match('/^\d{3}$/', $values['currency'])) {
        $errors[] = 'Para birimi 3 haneli kod olmalı. TRY: 949';
    }
    if (!in_array($values['txnType'], ['Auth', 'PreAuth'], true)) {
        $errors[] = 'İşlem tipi Auth veya PreAuth olmalı.';
    }
    if ($mode === '3dpay') {
        if (!preg_match('/^\d{13,19}$/', $pan)) {
            $errors[] = 'Kart numarası 13-19 hane olmalı.';
        }
        if (!preg_match('/^\d{4}$/', $values['expiry'])) {
            $errors[] = 'Son kullanma tarihi MMYY formatında olmalı.';
        }
        if ($values['cvv'] !== '' && !preg_match('/^\d{3,4}$/', $values['cvv'])) {
            $errors[] = 'CVV boş bırakılabilir veya 3-4 hane olmalı.';
        }
    }

    if ($errors === []) {
        $callback = qnb_callback_url();
        $rnd = microtime();
        $hash = qnb_request_hash(
            $values['mbrId'],
            $values['orderId'],
            $amount,
            $callback,
            $callback,
            $values['txnType'],
            $values['installmentCount'],
            $rnd,
            $values['merchantPass']
        );

        qnb_store_context([
            'orderId' => $values['orderId'],
            'merchantId' => $values['merchantId'],
            'merchantPass' => $values['merchantPass'],
            'userCode' => $values['userCode'],
            'mode' => $mode,
            'env' => $env,
            'created' => time(),
        ]);

        $fields = [
            'MbrId' => $values['mbrId'],
            'MerchantID' => $values['merchantId'],
            'UserCode' => $values['userCode'],
            'UserPass' => $values['userPass'],
            'SecureType' => qnb_secure_type($mode),
            'TxnType' => $values['txnType'],
            'InstallmentCount' => $values['installmentCount'],
            'Currency' => $values['currency'],
            'OkUrl' => $callback,
            'FailUrl' => $callback,
            'OrderId' => $values['orderId'],
            'PurchAmount' => $amount,
            'Lang' => 'TR',
            'Rnd' => $rnd,
            'Hash' => $hash,
        ];
        if ($mode === '3dpay') {
            $fields['Pan'] = $pan;
            $fields['Expiry'] = $values['expiry'];
            $fields['Cvv2'] = $values['cvv'];
        }

        $exchange = qnb_gateway_exchange(qnb_gateway($env, $mode), $fields);
        if (!$exchange['ok']) {
            $errors[] = 'Sunucu bankaya bağlanamadı. ' . $exchange['error'];
        } else {
            $location = qnb_bank_location($exchange['location']);
            if ($location !== '') {
                header('Location: ' . $location);
                exit;
            }
            $body = $exchange['body'];
            if ($body !== '' && (stripos($body, '<form') !== false || stripos($body, '<html') !== false)) {
                if (qnb_body_relays_credentials($body)) {
                    $errors[] = 'Banka cevabı üye işyeri bilgisini tarayıcıya geri yazıyor. İstek iletilmedi.';
                } else {
                    header('Content-Type: text/html; charset=UTF-8');
                    echo $body;
                    exit;
                }
            } else {
                $parsed = qnb_parse_gateway_response($body);
                $direct = [
                    'orderId' => $values['orderId'],
                    'amount' => $amount,
                    'status' => $exchange['status'],
                    'fields' => $parsed,
                    'raw' => $parsed === [] ? $body : '',
                ];
            }
        }
    }
}

$callbackUrl = qnb_callback_url();
$gatewayMap = [
    'test' => [
        '3dpay' => qnb_gateway('test', '3dpay'),
        '3dhost' => qnb_gateway('test', '3dhost'),
    ],
    'live' => [
        '3dpay' => qnb_gateway('live', '3dpay'),
        '3dhost' => qnb_gateway('live', '3dhost'),
    ],
];
$demoMap = [
    '3dpay' => qnb_demo('3dpay'),
    '3dhost' => qnb_demo('3dhost'),
];

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>QNB Sanal POS Test</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="wrap">
    <div class="card">
<?php if ($direct !== null): ?>
        <h1>Banka cevabı</h1>
        <p>Sipariş <strong><?= qnb_h($direct['orderId']) ?></strong>, tutar <strong><?= qnb_h($direct['amount']) ?></strong></p>
        <p class="muted">HTTP <?= qnb_h((string) $direct['status']) ?>. İstek sunucudan gitti.</p>
<?php if ($direct['fields'] !== []): ?>
        <table>
<?php foreach ($direct['fields'] as $name => $value): ?>
            <tr><th><?= qnb_h((string) $name) ?></th><td><?= qnb_h((string) $value) ?></td></tr>
<?php endforeach; ?>
        </table>
<?php elseif ($direct['raw'] !== ''): ?>
        <pre><?= qnb_h($direct['raw']) ?></pre>
<?php else: ?>
        <p>Banka boş cevap döndü.</p>
<?php endif; ?>
        <p><a class="btn secondary" href="index.php">Geri</a></p>
<?php else: ?>
        <h1>QNB Sanal POS Test</h1>
        <p class="muted">Ödeme isteğini bu sunucu bankaya gönderir. Kart doğrulama sayfası bankada açılır. <a href="refund.php">İade</a></p>
<?php if ($errors !== []): ?>
        <div class="errors">
            <ul>
<?php foreach ($errors as $error): ?>
                <li><?= qnb_h($error) ?></li>
<?php endforeach; ?>
            </ul>
        </div>
<?php endif; ?>
        <form method="post" action="index.php" autocomplete="off">
            <fieldset>
                <legend>Model</legend>
                <div class="choices">
                    <label><input type="radio" name="mode" value="3dpay" <?= $values['mode'] === '3dpay' ? 'checked' : '' ?>> 3DPay</label>
                    <label><input type="radio" name="mode" value="3dhost" <?= $values['mode'] === '3dhost' ? 'checked' : '' ?>> 3DHost</label>
                </div>
            </fieldset>
            <fieldset>
                <legend>Ortam</legend>
                <div class="choices">
                    <label><input type="radio" name="env" value="test" <?= $values['env'] === 'test' ? 'checked' : '' ?>> Test</label>
                    <label><input type="radio" name="env" value="live" <?= $values['env'] === 'live' ? 'checked' : '' ?>> Canlı</label>
                </div>
            </fieldset>
            <fieldset>
                <legend>Üye işyeri</legend>
                <div class="grid">
                    <label class="field"><span>Kurum kodu (MbrId)</span><input id="mbrId" name="mbrId" value="<?= qnb_h($values['mbrId']) ?>" required></label>
                    <label class="field"><span>Üye işyeri no (MerchantID)</span><input id="merchantId" name="merchantId" value="<?= qnb_h($values['merchantId']) ?>" required></label>
                    <label class="field"><span>3D şifresi (MerchantPass)</span><input id="merchantPass" name="merchantPass" value="<?= qnb_h($values['merchantPass']) ?>" required></label>
                    <label class="field"><span>Api kullanıcı (UserCode)</span><input id="userCode" name="userCode" value="<?= qnb_h($values['userCode']) ?>" required></label>
                    <label class="field"><span>Api şifre (UserPass)</span><input id="userPass" name="userPass" value="<?= qnb_h($values['userPass']) ?>" required></label>
                </div>
            </fieldset>
            <fieldset>
                <legend>İşlem</legend>
                <div class="grid">
                    <label class="field"><span>Sipariş no</span><input name="orderId" value="<?= qnb_h($values['orderId']) ?>" required></label>
                    <label class="field"><span>Tutar</span><input name="purchAmount" value="<?= qnb_h($values['purchAmount']) ?>" required></label>
                    <label class="field"><span>Taksit (0 = peşin)</span><input name="installmentCount" value="<?= qnb_h($values['installmentCount']) ?>" required></label>
                    <label class="field"><span>Para birimi</span><input name="currency" value="<?= qnb_h($values['currency']) ?>" required></label>
                    <label class="field"><span>İşlem tipi</span>
                        <select name="txnType">
                            <option value="Auth" <?= $values['txnType'] === 'Auth' ? 'selected' : '' ?>>Auth (Satış)</option>
                            <option value="PreAuth" <?= $values['txnType'] === 'PreAuth' ? 'selected' : '' ?>>PreAuth (Ön provizyon)</option>
                        </select>
                    </label>
                </div>
            </fieldset>
            <fieldset id="card-fields" <?= $values['mode'] === '3dhost' ? 'hidden' : '' ?>>
                <legend>Kart (3DPay)</legend>
                <div class="grid">
                    <label class="field"><span>Kart numarası</span><input id="pan" name="pan" inputmode="numeric" maxlength="19" value="<?= qnb_h($values['pan']) ?>"></label>
                    <label class="field"><span>Son kullanma (MMYY)</span><input id="expiry" name="expiry" inputmode="numeric" maxlength="4" value="<?= qnb_h($values['expiry']) ?>"></label>
                    <label class="field"><span>CVV</span><input id="cvv" name="cvv" inputmode="numeric" maxlength="4" value="<?= qnb_h($values['cvv']) ?>"></label>
                </div>
                <p class="muted">Test kartı: 4022780198283155, SKT 0150, CVV boş bırakılabilir.</p>
            </fieldset>
            <p class="muted">Dönüş adresi: <code><?= qnb_h($callbackUrl) ?></code></p>
            <p class="muted">Gateway: <code id="gateway-preview"><?= qnb_h($gatewayMap[$values['env']][$values['mode']]) ?></code></p>
            <button type="submit">Ödemeyi başlat</button>
        </form>
        <script>
            const demos = <?= json_encode($demoMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
            const gateways = <?= json_encode($gatewayMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
            const credFields = ['mbrId', 'merchantId', 'merchantPass', 'userCode', 'userPass'];

            function selected(name) {
                const el = document.querySelector('input[name="' + name + '"]:checked');
                return el ? el.value : '';
            }

            function syncForm(fillCredentials) {
                const mode = selected('mode');
                const env = selected('env');
                document.getElementById('card-fields').hidden = mode !== '3dpay';
                const preview = gateways[env] && gateways[env][mode] ? gateways[env][mode] : '';
                document.getElementById('gateway-preview').textContent = preview;
                if (!fillCredentials) {
                    return;
                }
                if (env === 'test' && demos[mode]) {
                    credFields.forEach(function (key) {
                        document.getElementById(key).value = demos[mode][key];
                    });
                } else {
                    credFields.forEach(function (key) {
                        document.getElementById(key).value = key === 'mbrId' ? '5' : '';
                    });
                }
            }

            document.querySelectorAll('input[name="mode"], input[name="env"]').forEach(function (el) {
                el.addEventListener('change', function () { syncForm(true); });
            });
        </script>
<?php endif; ?>
    </div>
</div>
</body>
</html>
