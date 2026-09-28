<?php
declare(strict_types=1);

require __DIR__ . '/lib/qnb.php';

$defaults = [
    'env' => 'live',
    'mbrId' => '5',
    'merchantId' => '',
    'userCode' => '',
    'userPass' => '',
    'orderId' => '',
    'purchAmount' => '',
    'currency' => '949',
];

$values = $defaults;
$errors = [];
$result = null;
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

if ($isPost) {
    foreach ($defaults as $key => $unused) {
        if (isset($_POST[$key]) && is_string($_POST[$key])) {
            $values[$key] = trim($_POST[$key]);
        }
    }

    $amount = str_replace(',', '.', $values['purchAmount']);
    $values['purchAmount'] = $amount;

    if (!in_array($values['env'], ['test', 'live'], true)) {
        $errors[] = 'Ortam test veya canlı olmalı.';
    }
    if (!preg_match('/^\d{1,4}$/', $values['mbrId'])) {
        $errors[] = 'Kurum kodu (MbrId) sayısal olmalı.';
    }
    if ($values['merchantId'] === '' || strlen($values['merchantId']) > 24) {
        $errors[] = 'Üye işyeri numarası gerekli.';
    }
    if ($values['userCode'] === '' || $values['userPass'] === '') {
        $errors[] = 'Api kullanıcı kodu ve şifresi gerekli.';
    }
    if (!preg_match('/^[A-Za-z0-9_-]{1,50}$/', $values['orderId'])) {
        $errors[] = 'Satıştaki sipariş numarası gerekli.';
    }
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $amount) || (float) $amount <= 0) {
        $errors[] = 'İade tutarı 0’dan büyük olmalı. Örnek: 1 veya 10.50';
    }
    if (!preg_match('/^\d{3}$/', $values['currency'])) {
        $errors[] = 'Para birimi 3 haneli kod olmalı. TRY: 949';
    }

    if ($errors === []) {
        $gateway = qnb_api_gateway($values['env']);
        $posted = qnb_gateway_post($gateway, [
            'MbrId' => $values['mbrId'],
            'MerchantID' => $values['merchantId'],
            'UserCode' => $values['userCode'],
            'UserPass' => $values['userPass'],
            'OrderId' => $values['orderId'],
            'SecureType' => 'NonSecure',
            'TxnType' => 'Refund',
            'PurchAmount' => $amount,
            'Currency' => $values['currency'],
            'Lang' => 'TR',
        ]);

        $parsed = $posted['ok'] ? qnb_parse_gateway_response($posted['raw']) : [];
        ksort($parsed);
        $result = [
            'gateway' => $gateway,
            'ok' => $posted['ok'],
            'error' => $posted['error'],
            'raw' => $posted['raw'],
            'parsed' => $parsed,
            'approved' => ($parsed['ProcReturnCode'] ?? '') === '00',
        ];
    }
}

$demo = qnb_demo('3dpay');
unset($demo['merchantPass']);
$gateways = [
    'test' => qnb_api_gateway('test'),
    'live' => qnb_api_gateway('live'),
];

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>QNB İade</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>İade</h1>
        <p class="muted">Satıştaki sipariş numarası ve iade tutarı ile <code>TxnType=Refund</code> gönderilir. Tutar satıştan küçükse kısmi iadedir. <a href="index.php">Ödeme formu</a></p>
<?php if ($errors !== []): ?>
        <div class="errors">
            <ul>
<?php foreach ($errors as $error): ?>
                <li><?= qnb_h($error) ?></li>
<?php endforeach; ?>
            </ul>
        </div>
<?php endif; ?>
<?php if ($result !== null): ?>
        <?php if (!$result['ok']): ?>
            <div class="banner bad">Banka isteği gönderilemedi. <?= qnb_h($result['error']) ?></div>
        <?php elseif ($result['approved']): ?>
            <div class="banner ok">İade onaylandı. ProcReturnCode 00.</div>
        <?php else: ?>
            <div class="banner bad">İade onaylanmadı<?= isset($result['parsed']['ProcReturnCode']) ? ' (ProcReturnCode ' . qnb_h($result['parsed']['ProcReturnCode']) . ')' : '' ?>.</div>
        <?php endif; ?>
        <?php if (($result['parsed']['ErrorMessage'] ?? '') !== ''): ?>
            <p><?= qnb_h($result['parsed']['ErrorMessage']) ?></p>
        <?php endif; ?>
        <p class="muted"><?= $values['env'] === 'live' ? 'Canlı' : 'Test' ?> · <code><?= qnb_h($result['gateway']) ?></code></p>
        <?php if ($result['parsed'] !== []): ?>
            <h2>Banka cevabı</h2>
            <table>
                <tr><th>Parametre</th><th>Değer</th></tr>
<?php foreach ($result['parsed'] as $key => $value): ?>
                <tr>
                    <td><?= qnb_h($key) ?></td>
                    <td><?= qnb_h(qnb_mask($key, $value)) ?></td>
                </tr>
<?php endforeach; ?>
            </table>
        <?php elseif ($result['raw'] !== ''): ?>
            <pre><?= qnb_h($result['raw']) ?></pre>
        <?php endif; ?>
<?php endif; ?>
        <form method="post" action="refund.php" autocomplete="off">
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
                    <label class="field"><span>Api kullanıcı (UserCode)</span><input id="userCode" name="userCode" value="<?= qnb_h($values['userCode']) ?>" required></label>
                    <label class="field"><span>Api şifre (UserPass)</span><input id="userPass" name="userPass" value="<?= qnb_h($values['userPass']) ?>" required></label>
                </div>
            </fieldset>
            <fieldset>
                <legend>İade</legend>
                <div class="grid">
                    <label class="field"><span>Sipariş no</span><input name="orderId" value="<?= qnb_h($values['orderId']) ?>" required></label>
                    <label class="field"><span>İade tutarı</span><input name="purchAmount" value="<?= qnb_h($values['purchAmount']) ?>" required></label>
                    <label class="field"><span>Para birimi</span><input name="currency" value="<?= qnb_h($values['currency']) ?>" required></label>
                </div>
            </fieldset>
            <p class="muted">Gateway: <code id="gateway-preview"><?= qnb_h($gateways[$values['env']]) ?></code></p>
            <button type="submit">İade gönder</button>
        </form>
        <script>
            const demo = <?= json_encode($demo, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
            const gateways = <?= json_encode($gateways, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
            const credFields = ['mbrId', 'merchantId', 'userCode', 'userPass'];

            function selectedEnv() {
                const el = document.querySelector('input[name="env"]:checked');
                return el ? el.value : 'live';
            }

            document.querySelectorAll('input[name="env"]').forEach(function (el) {
                el.addEventListener('change', function () {
                    const env = selectedEnv();
                    document.getElementById('gateway-preview').textContent = gateways[env] || '';
                    if (env === 'test') {
                        credFields.forEach(function (key) {
                            document.getElementById(key).value = demo[key];
                        });
                    } else {
                        credFields.forEach(function (key) {
                            document.getElementById(key).value = key === 'mbrId' ? '5' : '';
                        });
                    }
                });
            });
        </script>
    </div>
</div>
</body>
</html>
