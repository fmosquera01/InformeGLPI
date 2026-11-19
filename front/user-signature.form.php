<?php
declare(strict_types=1);

include('../../../inc/includes.php');
Session::checkLoginUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Html::displayErrorAndDie(__('Método no permitido.', 'informeglpi'));
}

global $DB, $CFG_GLPI;
$userId = (int) Session::getLoginUserID();
$signatureData = (string) ($_POST['signature_data'] ?? '');
$pin = (string) ($_POST['pin'] ?? '');
$pinConfirm = (string) ($_POST['pin_confirm'] ?? '');
$currentPin = (string) ($_POST['current_pin'] ?? '');
$destination = null;

try {
    if ($userId < 1) {
        throw new RuntimeException(__('No se pudo identificar al usuario conectado.', 'informeglpi'));
    }

    $table = 'glpi_plugin_informeglopi_user_signatures';
    if (!$DB->tableExists($table)) {
        throw new RuntimeException(__('La tabla de firmas no está instalada. Actualice el plugin desde Configuración > Plugins.', 'informeglpi'));
    }

    $existing = null;
    foreach ($DB->request([
        'SELECT' => ['id', 'signature_path', 'signature_sha256', 'pin_hash', 'failed_attempts', 'locked_until'],
        'FROM' => $table,
        'WHERE' => ['users_id' => $userId],
        'LIMIT' => 1,
    ]) as $row) {
        $existing = $row;
        break;
    }

    $changeType = is_array($existing) ? (string) ($_POST['change_type'] ?? 'signature') : 'both';
    if (!in_array($changeType, ['signature', 'pin', 'both'], true)) {
        throw new RuntimeException(__('Seleccione una opción de actualización válida.', 'informeglpi'));
    }
    $needsSignature = !is_array($existing) || in_array($changeType, ['signature', 'both'], true);
    $needsPin = !is_array($existing) || in_array($changeType, ['pin', 'both'], true);

    if (is_array($existing)) {
        $lockedUntil = (string) ($existing['locked_until'] ?? '');
        if ($lockedUntil !== '' && strtotime($lockedUntil) > time()) {
            throw new RuntimeException(__('Por seguridad, los cambios están temporalmente bloqueados. Intente más tarde.', 'informeglpi'));
        }
        if ($currentPin === '' || !password_verify($currentPin, (string) $existing['pin_hash'])) {
            $failures = (int) ($existing['failed_attempts'] ?? 0) + 1;
            $lock = $failures >= 5 ? date('Y-m-d H:i:s', time() + 15 * 60) : null;
            $DB->update($table, [
                'failed_attempts' => $failures >= 5 ? 0 : $failures,
                'locked_until' => $lock,
            ], ['id' => (int) $existing['id'], 'users_id' => $userId]);
            throw new RuntimeException(__('El PIN actual es incorrecto. Si alcanza 5 intentos, los cambios quedarán bloqueados durante 15 minutos.', 'informeglpi'));
        }
    }

    $values = [
        'failed_attempts' => 0,
        'locked_until' => null,
        'date_mod' => date('Y-m-d H:i:s'),
    ];

    if ($needsPin) {
        if (!preg_match('/^[0-9]{6}$/', $pin) || !hash_equals($pin, $pinConfirm)) {
            throw new RuntimeException(__('El PIN debe contener exactamente 6 dígitos y ambas entradas deben coincidir.', 'informeglpi'));
        }
        $values['pin_hash'] = password_hash($pin, PASSWORD_DEFAULT);
    }

    if ($needsSignature) {
        if (!preg_match('#^data:image/png;base64,#', $signatureData)) {
            throw new RuntimeException(__('Debe dibujar su firma antes de guardarla.', 'informeglpi'));
        }

        $encoded = substr($signatureData, strpos($signatureData, ',') + 1);
        $binary = base64_decode($encoded, true);
        if ($binary === false || strlen($binary) < 100 || strlen($binary) > 512 * 1024) {
            throw new RuntimeException(__('La imagen de firma está vacía o supera el tamaño permitido de 512 KB.', 'informeglpi'));
        }
        $imageInfo = @getimagesizefromstring($binary);
        if ($imageInfo === false || ($imageInfo[2] ?? 0) !== IMAGETYPE_PNG || ($imageInfo[0] ?? 0) !== 900 || ($imageInfo[1] ?? 0) !== 260) {
            throw new RuntimeException(__('La imagen de firma no tiene las dimensiones esperadas. Vuelva a dibujarla.', 'informeglpi'));
        }

        $baseDir = rtrim(GLPI_VAR_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '_plugins' . DIRECTORY_SEPARATOR . 'informeglpi' . DIRECTORY_SEPARATOR . 'signatures' . DIRECTORY_SEPARATOR . 'users';
        if (!is_dir($baseDir) && !mkdir($baseDir, 0750, true) && !is_dir($baseDir)) {
            throw new RuntimeException(__('No se pudo crear el directorio privado de firmas.', 'informeglpi'));
        }

        $fileName = $userId . '_' . bin2hex(random_bytes(16)) . '.png';
        $destination = $baseDir . DIRECTORY_SEPARATOR . $fileName;
        if (file_put_contents($destination, $binary, LOCK_EX) === false) {
            throw new RuntimeException(__('No se pudo guardar la firma.', 'informeglpi'));
        }
        chmod($destination, 0640);
        $values['signature_path'] = '_plugins/informeglpi/signatures/users/' . $fileName;
        $values['signature_sha256'] = hash('sha256', $binary);
    }

    if (is_array($existing)) {
        $DB->update($table, $values, ['id' => (int) $existing['id'], 'users_id' => $userId]);
    } else {
        $values['users_id'] = $userId;
        $values['date_creation'] = date('Y-m-d H:i:s');
        $DB->insert($table, $values);
    }

    $destination = null;
    $message = match ($changeType) {
        'signature' => __('Su firma personal se actualizó correctamente.', 'informeglpi'),
        'pin' => __('Su PIN personal se actualizó correctamente.', 'informeglpi'),
        default => __('Su firma personal y PIN se guardaron correctamente.', 'informeglpi'),
    };
    Session::addMessageAfterRedirect($message, true, INFO);
} catch (Throwable $e) {
    if (is_string($destination) && is_file($destination)) {
        @unlink($destination);
    }
    Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
}

$rootDoc = rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/');
Html::redirect($rootDoc . '/plugins/informeglpi/front/my-signature.php');
exit;
