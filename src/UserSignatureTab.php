<?php
declare(strict_types=1);

namespace GlpiPlugin\Informeglopi;

use CommonGLPI;

/**
 * Personal signature settings shown only on the authenticated user's GLPI card.
 */
final class UserSignatureTab extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return __('Firma personal', 'informeglpi');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof \User || (int) $item->getID() !== (int) \Session::getLoginUserID()) {
            return '';
        }

        return self::createTabEntry(self::getTypeName());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof \User || (int) $item->getID() !== (int) \Session::getLoginUserID()) {
            return true;
        }

        self::render();
        return true;
    }

    private static function render(): void
    {
        global $DB;

        $userId = (int) \Session::getLoginUserID();
        $configured = false;
        $signatureHash = '';
        if ($DB->tableExists('glpi_plugin_informeglopi_user_signatures')) {
            $configured = $DB->request([
                'SELECT' => ['id', 'signature_sha256'],
                'FROM' => 'glpi_plugin_informeglopi_user_signatures',
                'WHERE' => ['users_id' => $userId],
                'LIMIT' => 1,
            ]);
            foreach ($rows as $row) {
                $configured = true;
                $signatureHash = (string) ($row['signature_sha256'] ?? '');
                break;
            }
        }

        echo "<div class='card mb-3'><div class='card-header'><strong>" . self::e(__('Firma personal para informes técnicos', 'informeglpi')) . "</strong></div><div class='card-body'>";
        echo "<p>" . self::e(__('Registre una sola vez su firma manuscrita. Se utilizará únicamente cuando usted autorice la firma de un informe técnico.', 'informeglpi')) . "</p>";
        if ($configured) {
            $previewUrl = '/plugins/informeglpi/front/user-signature.image.php?v=' . rawurlencode($signatureHash);
            echo "<div class='mb-3'><label class='form-label fw-bold'>" . self::e(__('Firma actualmente registrada', 'informeglpi')) . "</label><div class='border rounded bg-white p-3' style='max-width:760px'><img src='" . self::e($previewUrl) . "' alt='" . self::e(__('Firma personal registrada', 'informeglpi')) . "' style='display:block;max-width:100%;max-height:180px;object-fit:contain;margin:auto'></div><small class='text-muted'>" . self::e(__('Esta es la firma que se utilizará cuando autorice un informe. Para reemplazarla, dibuje una nueva debajo.', 'informeglpi')) . "</small></div>";
            echo "<div class='alert alert-success'>" . self::e(__('Ya tiene una firma y un PIN registrados. Para reemplazar la firma o cambiar el PIN deberá ingresar su PIN actual.', 'informeglpi')) . "</div>";
        } else {
            echo "<div class='alert alert-info'>" . self::e(__('Todavía no tiene una firma registrada. Dibuje su firma y cree un PIN personal de 6 dígitos.', 'informeglpi')) . "</div>";
        }

        echo "<form method='post' action='/plugins/informeglpi/front/user-signature.form.php' id='ig-user-signature-form'>";
        echo \Html::hidden('_glpi_csrf_token', ['value' => \Session::getNewCSRFToken(true)]);
        echo "<label class='form-label fw-bold'>" . self::e(__('Dibuje su firma', 'informeglpi')) . "</label>";
        echo "<div class='border rounded bg-white' style='max-width:760px;touch-action:none;overflow:hidden'>";
        echo "<canvas id='ig-signature-pad' width='900' height='260' style='display:block;width:100%;height:auto;touch-action:none;cursor:crosshair'></canvas>";
        echo "</div><input type='hidden' name='signature_data' id='ig-signature-data'>";
        echo "<button type='button' class='btn btn-sm btn-outline-secondary mt-2' id='ig-signature-clear'>" . self::e(__('Borrar y volver a firmar', 'informeglpi')) . "</button>";
        if ($configured) {
            echo "<div class='mt-3'><label class='form-label'>" . self::e(__('PIN actual', 'informeglpi')) . "</label><input class='form-control' style='max-width:240px' type='password' name='current_pin' inputmode='numeric' pattern='[0-9]{6}' maxlength='6' autocomplete='current-password' required></div>";
        }
        echo "<div class='row mt-3'><div class='col-md-4'><label class='form-label'>" . self::e($configured ? __('Nuevo PIN (6 dígitos)', 'informeglpi') : __('Crear PIN (6 dígitos)', 'informeglpi')) . "</label><input class='form-control' type='password' name='pin' inputmode='numeric' pattern='[0-9]{6}' minlength='6' maxlength='6' autocomplete='new-password' required></div>";
        echo "<div class='col-md-4'><label class='form-label'>" . self::e(__('Confirmar PIN', 'informeglpi')) . "</label><input class='form-control' type='password' name='pin_confirm' inputmode='numeric' pattern='[0-9]{6}' minlength='6' maxlength='6' autocomplete='new-password' required></div></div>";
        echo "<div class='mt-3'><button type='submit' class='btn btn-primary'>" . self::e($configured ? __('Actualizar firma y PIN', 'informeglpi') : __('Guardar mi firma', 'informeglpi')) . "</button></div>";
        echo "</form></div></div>";

        echo "<script>
        (() => {
          const canvas = document.getElementById('ig-signature-pad');
          const form = document.getElementById('ig-user-signature-form');
          if (!canvas || !form) return;
          const ctx = canvas.getContext('2d');
          ctx.lineWidth = 4; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#111827';
          let drawing = false, hasInk = false;
          const point = (event) => {
            const rect = canvas.getBoundingClientRect();
            return { x: (event.clientX - rect.left) * canvas.width / rect.width, y: (event.clientY - rect.top) * canvas.height / rect.height };
          };
          canvas.addEventListener('pointerdown', (event) => { drawing = true; hasInk = true; canvas.setPointerCapture(event.pointerId); const p = point(event); ctx.beginPath(); ctx.moveTo(p.x,p.y); });
          canvas.addEventListener('pointermove', (event) => { if (!drawing) return; const p = point(event); ctx.lineTo(p.x,p.y); ctx.stroke(); });
          const stop = () => { drawing = false; };
          canvas.addEventListener('pointerup', stop); canvas.addEventListener('pointercancel', stop);
          document.getElementById('ig-signature-clear')?.addEventListener('click', () => { ctx.clearRect(0,0,canvas.width,canvas.height); hasInk = false; });
          form.addEventListener('submit', (event) => {
            if (!hasInk) { event.preventDefault(); alert('Debe dibujar su firma antes de guardarla.'); return; }
            document.getElementById('ig-signature-data').value = canvas.toDataURL('image/png');
          });
        })();
        </script>";
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
