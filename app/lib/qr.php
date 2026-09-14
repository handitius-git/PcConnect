<?php

declare(strict_types=1);

/**
 * Enkripsi payload kode aset (AES-256-CBC) sebelum dikirim ke HTTP QR generator.
 */
function qr_encrypt_payload(string $plainCode): string
{
    $plainCode = trim($plainCode);
    if ($plainCode === '') {
        return '';
    }
    $token = (string)config_value('agent_token');
    $pass = (string)config_value('db_pass');
    $key = hash('sha256', $token . ':' . $pass . ':pcconnect_qr_key_v1', true);
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plainCode, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) {
        return $plainCode;
    }
    return rtrim(strtr(base64_encode($iv . $cipher), '+/', '-_'), '=');
}

/**
 * Dekripsi payload terenkripsi dari parameter scanner QR.
 */
function qr_decrypt_payload(string $encryptedToken): ?string
{
    $encryptedToken = trim($encryptedToken);
    if ($encryptedToken === '') {
        return null;
    }
    $raw = base64_decode(strtr($encryptedToken, '-_', '+/'));
    if ($raw === false || strlen($raw) <= 16) {
        return null;
    }
    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    $token = (string)config_value('agent_token');
    $pass = (string)config_value('db_pass');
    $key = hash('sha256', $token . ':' . $pass . ':pcconnect_qr_key_v1', true);
    $decrypted = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return $decrypted !== false ? trim((string)$decrypted) : null;
}

/**
 * URL mobile aset dengan payload terenkripsi untuk cetak QR.
 */
function mobile_encrypted_asset_url(string $assetCode): string
{
    $encrypted = qr_encrypt_payload($assetCode);
    $mobileBase = (string)config_value('mobile_base_url');
    if (str_contains($mobileBase, 'code=')) {
        return str_replace('code=', 'eqr=', $mobileBase) . rawurlencode($encrypted);
    }
    $sep = str_contains($mobileBase, '?') ? '&' : '?';
    return $mobileBase . $sep . 'eqr=' . rawurlencode($encrypted);
}

/**
 * URL pembuatan QR Code via HTTP (api.qrserver.com) dengan enkripsi payload.
 */
function qr_image_url(string $value, int $size = 900): string
{
    $size = max(120, min(1200, $size));

    // Jika value adalah kode aset langsung (bukan URL), otomatis enkripsikan ke mobile URL terenkripsi
    if (!str_starts_with($value, 'http://') && !str_starts_with($value, 'https://')) {
        $value = mobile_encrypted_asset_url($value);
    } elseif (str_contains($value, 'route=mobile_scan') && str_contains($value, 'code=')) {
        // Jika URL masih menggunakan parameter plaintext code=, ganti ke eqr= terenkripsi
        if (preg_match('/[?&]code=([^&#]+)/', $value, $m)) {
            $plain = urldecode($m[1]);
            $encrypted = qr_encrypt_payload($plain);
            $value = str_replace('code=' . $m[1], 'eqr=' . rawurlencode($encrypted), $value);
        }
    }

    return 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size . '&format=png&data=' . rawurlencode($value);
}

function pseudo_qr_hotfix(string $value): string
{
    $src = qr_image_url($value, 180);
    return '<img class="qr-img" alt="QR Code" src="' . e($src) . '">';
}

function label_font_path(bool $bold = false): ?string
{
    $candidates = $bold ? [
        'C:/Windows/Fonts/arialbd.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
    ] : [
        'C:/Windows/Fonts/arial.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans.ttf',
    ];
    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    return null;
}

function label_center_text($img, string $text, int $size, int $y, int $color, bool $bold = false): void
{
    $text = trim($text);
    if ($text === '') {
        return;
    }
    $maxChars = $size >= 50 ? 22 : ($size >= 30 ? 38 : 52);
    if (strlen($text) > $maxChars) {
        $text = substr($text, 0, $maxChars - 3) . '...';
    }
    $fontPath = label_font_path($bold);
    if ($fontPath && function_exists('imagettfbbox') && function_exists('imagettftext')) {
        $box = imagettfbbox($size, 0, $fontPath, $text);
        if ($box) {
            $textWidth = $box[2] - $box[0];
            $textHeight = abs($box[7] - $box[1]);
            $x = (int)((imagesx($img) - $textWidth) / 2);
            imagettftext($img, $size, 0, max(24, $x), $y + $textHeight, $color, $fontPath, $text);
            return;
        }
    }

    $font = 5;
    $scale = max(2, (int)round($size / 10));
    $textWidth = imagefontwidth($font) * strlen($text);
    $textHeight = imagefontheight($font);
    $tmp = imagecreatetruecolor($textWidth + 8, $textHeight + 8);
    $bg = imagecolorallocate($tmp, 255, 255, 255);
    imagefilledrectangle($tmp, 0, 0, imagesx($tmp), imagesy($tmp), $bg);
    imagecolortransparent($tmp, $bg);
    imagestring($tmp, $font, 4, 4, $text, $color);
    $scaledWidth = imagesx($tmp) * $scale;
    $scaledHeight = imagesy($tmp) * $scale;
    $x = (int)((imagesx($img) - $scaledWidth) / 2);
    imagecopyresampled($img, $tmp, max(24, $x), $y, 0, 0, $scaledWidth, $scaledHeight, imagesx($tmp), imagesy($tmp));
    imagedestroy($tmp);
}

function label_download_script(): string
{
    return '<script>(function(){function fitText(ctx,text,maxWidth,startSize,minSize){text=String(text||"").trim();var size=startSize;ctx.font="700 "+size+"px Arial";while(size>minSize&&ctx.measureText(text).width>maxWidth){size-=2;ctx.font="700 "+size+"px Arial";}return size;}function drawCentered(ctx,text,y,size,color,bold){text=String(text||"").trim();if(!text)return;ctx.fillStyle=color||"#111827";ctx.font=(bold?"700 ":"400 ")+size+"px Arial";ctx.textAlign="center";ctx.textBaseline="top";ctx.fillText(text,450,y);}function loadImage(src){return new Promise(function(resolve,reject){var img=new Image();img.crossOrigin="anonymous";img.onload=function(){resolve(img);};img.onerror=reject;img.src=src;});}async function downloadLabel(btn){var code=btn.dataset.code||"",payload=btn.dataset.payload||"",type=btn.dataset.type||"Maintenance Asset",name=btn.dataset.name||"",detail=btn.dataset.detail||"",qrSrc=' . js_value(qr_image_url('__PAYLOAD__', 900)) . '.replace("__PAYLOAD__",encodeURIComponent(payload));var qr=await loadImage(qrSrc);var canvas=document.createElement("canvas"),ctx=canvas.getContext("2d");canvas.width=900;canvas.height=1180;ctx.fillStyle="#fff";ctx.fillRect(0,0,900,1180);ctx.strokeStyle="#111827";ctx.lineWidth=2;ctx.strokeRect(20,20,860,1140);var codeSize=fitText(ctx,code,780,58,28);drawCentered(ctx,code,66,codeSize,"#111827",true);drawCentered(ctx,type,175,28,"#475569",false);ctx.drawImage(qr,145,260,610,610);drawCentered(ctx,"PcConnect Maintenance QR",925,30,"#111827",true);drawCentered(ctx,name,1005,26,"#475569",false);drawCentered(ctx,detail,1065,24,"#475569",false);var a=document.createElement("a");a.href=canvas.toDataURL("image/png");a.download="PcConnect-Label-"+code.replace(/[^A-Z0-9_-]/g,"")+".png";document.body.appendChild(a);a.click();a.remove();}document.addEventListener("click",function(ev){var btn=ev.target.closest(".js-label-download");if(!btn)return;ev.preventDefault();var old=btn.textContent;btn.textContent="Membuat PNG...";downloadLabel(btn).catch(function(){window.location.href=btn.href;}).finally(function(){btn.textContent=old;});});})();</script>';
}

