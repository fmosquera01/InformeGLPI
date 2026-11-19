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

    public static function render(): void
    {
        global $DB, $CFG_GLPI;

        $userId = (int) \Session::getLoginUserID();
        $configured = false;
        $signatureHash = '';
        if ($DB->tableExists('glpi_plugin_informeglopi_user_signatures')) {
            foreach ($DB->request([
                'SELECT' => ['id', 'signature_sha256'],
                'FROM' => 'glpi_plugin_informeglopi_user_signatures',
                'WHERE' => ['users_id' => $userId],
                'LIMIT' => 1,
            ]) as $row) {
                $configured = true;
                $signatureHash = (string) ($row['signature_sha256'] ?? '');
                break;
            }
        }

        $rootDoc = rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/');
        $imageUrl = $rootDoc . '/plugins/informeglpi/front/user-signature.image.php?v=' . rawurlencode($signatureHash);
        $formUrl = $rootDoc . '/plugins/informeglpi/front/user-signature.form.php';

        echo "<div class='card mb-3'><div class='card-header'><strong>Mi firma personal</strong></div><div class='card-body'>";
        echo "<p>Administre su firma y su PIN. Puede actualizar cada elemento por separado, sin tener que volver a registrar ambos.</p>";
        if ($configured) {
            echo "<div class='mb-3'><label class='form-label fw-bold'>Firma actualmente registrada</label><div class='border rounded bg-white p-3' style='max-width:760px'><img src='" . self::e($imageUrl) . "' alt='Firma personal registrada' style='display:block;max-width:100%;max-height:180px;object-fit:contain;margin:auto'></div></div>";
            echo "<div class='alert alert-info'>Para cualquier cambio deberá confirmar su PIN actual. El PIN nunca se muestra en pantalla.</div>";
            echo "<div class='mb-3'><label class='form-label fw-bold' for='ig-signature-change-type'>¿Qué desea actualizar?</label><select class='form-select' id='ig-signature-change-type' name='change_type' form='ig-user-signature-form' style='max-width:360px'><option value='signature'>Cambiar únicamente mi firma</option><option value='pin'>Cambiar únicamente mi PIN</option><option value='both'>Cambiar firma y PIN</option></select></div>";
        } else {
            echo "<div class='alert alert-info'>Todavía no tiene una firma registrada. Dibuje su firma y cree un PIN personal de 6 dígitos.</div>";
        }

        echo "<form method='post' action='" . self::e($formUrl) . "' id='ig-user-signature-form'>";
        echo \Html::hidden('_glpi_csrf_token', ['value' => \Session::getNewCSRFToken(true)]);
        if (!$configured) {
            echo "<input type='hidden' name='change_type' value='both'>";
        } else {
            echo "<div class='mb-3'><label class='form-label' for='ig-current-pin'>PIN actual</label><input class='form-control' style='max-width:240px' type='password' id='ig-current-pin' name='current_pin' inputmode='numeric' pattern='[0-9]{6}' maxlength='6' autocomplete='current-password' required></div>";
        }

        echo "<div id='ig-signature-section'>";
        echo "<label class='form-label fw-bold'>Dibuje su nueva firma</label>";
        echo "<div class='border rounded bg-white' style='max-width:760px;touch-action:none;overflow:hidden'>";
        echo "<canvas id='ig-signature-pad' width='900' height='260' style='display:block;width:100%;height:auto;touch-action:none;cursor:crosshair'></canvas>";
        echo "</div><input type='hidden' name='signature_data' id='ig-signature-data'>";
        echo "<button type='button' class='btn btn-sm btn-outline-secondary mt-2' id='ig-signature-clear'>Borrar y volver a firmar</button>";
        echo "</div>";

        echo "<div id='ig-pin-section' class='mt-3'>";
        echo "<div class='row'><div class='col-md-4'><label class='form-label'>" . self::e($configured ? 'Nuevo PIN (6 dígitos)' : 'Crear PIN (6 dígitos)') . "</label><input class='form-control' type='password' name='pin' inputmode='numeric' pattern='[0-9]{6}' minlength='6' maxlength='6' autocomplete='new-password'></div>";
        echo "<div class='col-md-4'><label class='form-label'>Confirmar PIN</label><input class='form-control' type='password' name='pin_confirm' inputmode='numeric' pattern='[0-9]{6}' minlength='6' maxlength='6' autocomplete='new-password'></div></div>";
        echo "</div>";
        echo "<div class='mt-3'><button type='submit' class='btn btn-primary'>" . self::e($configured ? 'Guardar cambios' : 'Guardar mi firma') . "</button></div>";
        echo "</form></div></div>";

        echo "<script>
        (() => {
          const canvas = document.getElementById('ig-signature-pad');
          const form = document.getElementById('ig-user-signature-form');
          if (!form) return;
          const mode = document.getElementById('ig-signature-change-type');
          const signatureSection = document.getElementById('ig-signature-section');
          const pinSection = document.getElementById('ig-pin-section');
          const signatureInput = document.getElementById('ig-signature-data');
          const pinInputs = pinSection?.querySelectorAll('input') || [];
          const updateMode = () => {
            const value = mode?.value || 'both';
            const needsSignature = value === 'signature' || value === 'both';
            const needsPin = value === 'pin' || value === 'both';
            if (signatureSection) signatureSection.hidden = !needsSignature;
            if (pinSection) pinSection.hidden = !needsPin;
            if (canvas) canvas.dataset.required = needsSignature ? '1' : '0';
            pinInputs.forEach(input => input.required = needsPin);
          };
          mode?.addEventListener('change', updateMode);
          updateMode();
          if (!canvas) return;
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
            const needsSignature = !canvas.dataset.required || canvas.dataset.required === '1';
            if (needsSignature && !hasInk) { event.preventDefault(); alert('Debe dibujar su firma antes de guardar.'); return; }
            if (needsSignature && signatureInput) signatureInput.value = canvas.toDataURL('image/png');
          });
        })();
        </script>";
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
