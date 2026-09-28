<?php
declare(strict_types=1);

require __DIR__ . '/lib/qnb.php';

qnb_session_start();
header('Content-Type: text/html; charset=UTF-8');

$post = [];
foreach ($_POST as $key => $value) {
    if (is_string($key) && is_string($value)) {
        $post[$key] = $value;
    }
}

$orderId = $post['OrderId'] ?? '';
$ctx = $orderId !== '' ? qnb_load_context($orderId) : null;

$merchantId = $post['MerchantID'] ?? (string) ($ctx['merchantId'] ?? '');
$userCode = $post['UserCode'] ?? (string) ($ctx['userCode'] ?? '');
$authCode = $post['AuthCode'] ?? '';
$procReturnCode = $post['ProcReturnCode'] ?? '';
$threeDStatus = $post['3DStatus'] ?? '';
$responseRnd = $post['ResponseRnd'] ?? '';
$responseHash = trim($post['ResponseHash'] ?? '');

$hashChecked = false;
$hashOk = false;
$expectedHash = '';

if ($ctx !== null && isset($ctx['merchantPass'])) {
    $hashChecked = true;
    $expectedHash = qnb_response_hash(
        $merchantId,
        (string) $ctx['merchantPass'],
        $orderId,
        $authCode,
        $procReturnCode,
        $threeDStatus,
        $responseRnd,
        $userCode
    );
    $hashOk = $responseHash !== '' && hash_equals($expectedHash, $responseHash);
    qnb_clear_context($orderId);
}

$paid = $hashOk && $threeDStatus === '1' && $procReturnCode === '00';
ksort($post);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>QNB Ödeme Sonucu</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>Ödeme sonucu</h1>
<?php if ($post === []): ?>
        <div class="banner warn">Banka dönüşü yok. Ödeme bu adrese POST ile döner.</div>
<?php elseif ($paid): ?>
        <div class="banner ok">Ödeme başarılı. 3D doğrulama, provizyon ve response hash uyumlu.</div>
<?php else: ?>
        <div class="banner bad">Ödeme başarılı sayılmadı.</div>
        <?php if ($threeDStatus === '1'): ?>
            <p>3D doğrulama başarılı.</p>
        <?php else: ?>
            <p>3D doğrulama hatalı<?= $threeDStatus !== '' ? ' (3DStatus ' . qnb_h($threeDStatus) . ')' : '' ?>.</p>
        <?php endif; ?>
        <?php if ($procReturnCode === '00'): ?>
            <p>Provizyon kodu 00.</p>
        <?php else: ?>
            <p>Provizyon hatalı<?= $procReturnCode !== '' ? ' (ProcReturnCode ' . qnb_h($procReturnCode) . ')' : '' ?>.</p>
        <?php endif; ?>
        <?php if (!$hashChecked): ?>
            <p>MerchantPass bulunamadı. Response hash doğrulanamadı.</p>
        <?php elseif ($hashOk): ?>
            <p>Response hash doğrulandı.</p>
        <?php else: ?>
            <p>Response hash uyuşmuyor.</p>
        <?php endif; ?>
<?php endif; ?>

<?php if ($ctx !== null): ?>
        <p class="muted"><?= ($ctx['mode'] ?? '') === '3dhost' ? '3DHost' : '3DPay' ?> · <?= ($ctx['env'] ?? '') === 'live' ? 'Canlı' : 'Test' ?></p>
<?php endif; ?>

<?php if ($post !== []): ?>
        <h2>Dönüş parametreleri</h2>
        <table>
            <tr><th>Parametre</th><th>Değer</th></tr>
<?php foreach ($post as $key => $value): ?>
            <tr>
                <td><?= qnb_h($key) ?></td>
                <td><?= qnb_h(qnb_mask($key, $value)) ?></td>
            </tr>
<?php endforeach; ?>
<?php if ($hashChecked && !$hashOk): ?>
            <tr>
                <td>Hesaplanan ResponseHash</td>
                <td><?= qnb_h($expectedHash) ?></td>
            </tr>
<?php endif; ?>
        </table>
<?php endif; ?>
        <p><a class="btn" href="index.php">Yeni işlem</a></p>
    </div>
</div>
</body>
</html>
