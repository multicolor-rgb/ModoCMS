<?php
/**
 * Shared renderer for the Customizer "Customize" tab.
 *
 * Expected scope when included: $schema (array) and $values (array).
 * Emits ONLY the inner content of `[data-panel="customize"]` so both the panel
 * page and the AJAX refresh endpoint produce identical markup.
 */
use Core\Customizer;

if (!function_exists('modo_render_control')) {
    /**
     * Renders a single sidebar control for the given type.
     *
     * @param array<string,mixed> $control
     */
    function modo_render_control(array $control, string $value): void
    {
        $id = htmlspecialchars($control['id'], ENT_QUOTES, 'UTF-8');
        $type = $control['type'];
        $name = 'modo-mod';
        $dataAttr = 'data-control-id="' . $id . '" data-control-type="' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '"';

        switch ($type) {
            case 'heading':
                echo '<h4 class="modo-control-heading">' . htmlspecialchars((string) $control['label'], ENT_QUOTES, 'UTF-8') . '</h4>';
                return;

            case 'hr':
                echo '<hr class="modo-control-hr">';
                return;

            case 'textarea':
            case 'code':
                echo '<textarea class="form-control modo-control" rows="4" name="' . $name . '" ' . $dataAttr . '>'
                    . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</textarea>';
                return;

            case 'checkbox':
            case 'toggle':
                $checked = ($value === '1') ? ' checked' : '';
                echo '<label class="modo-toggle">';
                echo '<input type="checkbox" class="modo-control" name="' . $name . '" value="1"' . $checked . ' ' . $dataAttr . '>';
                echo '<span class="modo-toggle-track"><span class="modo-toggle-thumb"></span></span>';
                echo '</label>';
                return;

            case 'number':
            case 'range':
                $min = isset($control['min']) && $control['min'] !== null ? ' min="' . htmlspecialchars((string) $control['min'], ENT_QUOTES, 'UTF-8') . '"' : '';
                $max = isset($control['max']) && $control['max'] !== null ? ' max="' . htmlspecialchars((string) $control['max'], ENT_QUOTES, 'UTF-8') . '"' : '';
                $step = isset($control['step']) && $control['step'] !== null ? ' step="' . htmlspecialchars((string) $control['step'], ENT_QUOTES, 'UTF-8') . '"' : '';
                $extra = $type === 'range'
                    ? ' class="form-range modo-control" oninput="this.parentNode.querySelector(\'.modo-range-value\') && (this.parentNode.querySelector(\'.modo-range-value\').textContent = this.value)"'
                    : ' class="form-control modo-control"';
                echo '<div class="modo-range-wrap">';
                echo '<input type="' . $type . '"' . $extra . ' name="' . $name . '" value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"' . $min . $max . $step . ' ' . $dataAttr . '>';
                if ($type === 'range') {
                    echo '<output class="modo-range-value">' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</output>';
                }
                echo '</div>';
                return;

            case 'select':
                echo '<select class="form-control modo-control" name="' . $name . '" ' . $dataAttr . '>';
                foreach ($control['options'] as $option) {
                    $selected = ((string) $option['value'] === $value) ? ' selected' : '';
                    echo '<option value="' . htmlspecialchars($option['value'], ENT_QUOTES, 'UTF-8') . '"' . $selected . '>'
                        . htmlspecialchars($option['label'], ENT_QUOTES, 'UTF-8') . '</option>';
                }
                echo '</select>';
                return;

            case 'radio':
                foreach ($control['options'] as $option) {
                    $checked = ((string) $option['value'] === $value) ? ' checked' : '';
                    echo '<label class="modo-radio">';
                    echo '<input type="radio" class="modo-control" name="' . $name . '" value="'
                        . htmlspecialchars($option['value'], ENT_QUOTES, 'UTF-8') . '"' . $checked . ' ' . $dataAttr . '>';
                    echo '<span>' . htmlspecialchars($option['label'], ENT_QUOTES, 'UTF-8') . '</span>';
                    echo '</label>';
                }
                return;

            case 'color':
                echo '<div class="modo-color-wrap">';
                echo '<input type="color" class="modo-color-input modo-control" name="' . $name . '" value="'
                    . htmlspecialchars($value !== '' ? $value : '#000000', ENT_QUOTES, 'UTF-8') . '" ' . $dataAttr . '>';
                echo '<input type="text" class="form-control modo-color-text" value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '" readonly>';
                echo '</div>';
                return;

            case 'image':
                echo '<div class="modo-media-wrap">';
                echo '<input type="text" class="form-control modo-control" name="' . $name . '" value="'
                    . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '" ' . $dataAttr . '>';
                echo '<button type="button" class="btn btn-secondary modo-media-btn">' . _e('Select') . '</button>';
                echo '<input type="file" class="modo-media-picker" accept="image/*" style="display:none;">';
                echo '</div>';
                return;

            case 'url':
            case 'email':
            case 'text':
            default:
                $inputType = ($type === 'url' || $type === 'email') ? $type : 'text';
                echo '<input type="' . $inputType . '" class="form-control modo-control" name="' . $name . '" value="'
                    . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '" ' . $dataAttr . '>';
                return;
        }
    }
}
?>
<?php if (empty($schema['sections'])): ?>
    <p class="modo-cz-empty"><?= _e('No settings defined yet. Use the "Manage settings" tab to add some.') ?></p>
<?php endif; ?>

<?php foreach ($schema['sections'] as $section): ?>
    <?php if (empty($section['controls'])) { continue; } ?>
    <details class="modo-cz-section" open>
        <summary>
            <span><?= htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8') ?></span>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
        </summary>
        <div class="modo-cz-section-body">
            <?php if (!empty($section['description'])): ?>
                <p class="modo-cz-section-desc"><?= htmlspecialchars($section['description'], ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
            <?php foreach ($section['controls'] as $control): ?>
                <div class="modo-cz-control" data-control-id="<?= htmlspecialchars($control['id'], ENT_QUOTES, 'UTF-8') ?>">
                    <?php if (!in_array($control['type'], Customizer::LAYOUT_TYPES, true)): ?>
                        <label class="modo-cz-label"><?= htmlspecialchars($control['label'], ENT_QUOTES, 'UTF-8') ?></label>
                    <?php endif; ?>
                    <?php modo_render_control($control, (string) ($values[$control['id']] ?? '')); ?>
                    <?php if (!empty($control['description'])): ?>
                        <p class="modo-cz-desc"><?= htmlspecialchars($control['description'], ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </details>
<?php endforeach; ?>

