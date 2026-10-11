<?php
/**
 * Reusable existing-customer picker (used on the dashboards now; the Phase 4 forms embed it with mode "field").
 * Vars: $pickerId (string), $pickerMode ('select' | 'field'), $pickerField (hidden input name for 'field' mode).
 * All behaviour lives in /assets/js/customer-picker.js, driven by the data-* attributes below (CSP forbids inline JS).
 */
$pickerMode = $pickerMode ?? 'select';
$pickerField = $pickerField ?? 'customer_id';
$pickerValue = $pickerValue ?? '';
$pickerLabel = $pickerLabel ?? '';
?>
<div class="customer-picker" data-picker data-mode="<?= e($pickerMode) ?>" data-lookup-url="/customers/lookup" data-csrf="<?= e(\App\Helpers\Csrf::token()) ?>">
  <label for="<?= e($pickerId) ?>" class="form-label fw-semibold">Find an existing customer</label>
  <input type="search" id="<?= e($pickerId) ?>" class="form-control" placeholder="Name, phone number or BDC-000123" autocomplete="off" maxlength="80" aria-describedby="<?= e($pickerId) ?>-hint" data-picker-input>
  <?php if ($pickerMode === 'field'): ?><input type="hidden" name="<?= e($pickerField) ?>" value="<?= e($pickerValue) ?>" data-picker-value><?php endif; ?>
  <div class="form-text" id="<?= e($pickerId) ?>-hint" data-picker-hint>Type at least 2 letters, 4 digits or a customer ID.</div>
  <div class="list-group mt-2" data-picker-results aria-live="polite"></div>
  <div class="alert alert-success py-2 mt-2<?= $pickerValue === '' ? ' d-none' : '' ?>" data-picker-chosen><?= $pickerValue !== '' ? e($pickerLabel) : '' ?></div>
</div>
