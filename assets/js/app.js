/* Vanilla JS: dynamic logger (type switch, add/remove exercises, pace), profile hints, confirms. */
document.addEventListener('DOMContentLoaded', () => {

  // ---------------- Workout logger ----------------
  const form = document.getElementById('log-form');
  if (form) {
    const groups  = form.querySelectorAll('.group');
    const rowsBox = document.getElementById('ex-rows');
    const tpl     = document.getElementById('ex-template');
    const addBtn  = document.getElementById('add-ex');
    const typeNow = () => form.querySelector('input[name="workout_type"]:checked')?.value;

    // Show only the fields for the chosen type. Disabled inputs are not submitted.
    const applyType = () => {
      const type = typeNow();
      groups.forEach(g => {
        const show = g.dataset.types.split(' ').includes(type);
        g.hidden = !show;
        g.querySelectorAll('input').forEach(i => { i.disabled = !show; });
      });
      // Weight field only for weightlifting (overrides the group-level enable above).
      form.querySelectorAll('.weight-field').forEach(w => {
        const on = type === 'weightlifting';
        w.hidden = !on;
        w.querySelector('input').disabled = !on;
      });
      updateRemoveButtons();
    };

    // Hide "Remove" when only one exercise row is left.
    const updateRemoveButtons = () => {
      const rows = rowsBox.querySelectorAll('.ex-row');
      rows.forEach(r => r.querySelector('.remove-row').hidden = rows.length < 2);
    };

    addBtn?.addEventListener('click', () => {
      rowsBox.appendChild(tpl.content.cloneNode(true));
      applyType();
      rowsBox.lastElementChild.querySelector('input')?.focus();
    });
    rowsBox.addEventListener('click', e => {
      if (e.target.classList.contains('remove-row')) {
        e.target.closest('.ex-row').remove();
        updateRemoveButtons();
      }
    });

    form.querySelectorAll('input[name="workout_type"]').forEach(r => r.addEventListener('change', applyType));
    applyType();

    // Live pace preview (running)
    const paceOut = document.getElementById('pace-out');
    const val = n => parseFloat(form.elements[n]?.value) || 0;
    const updatePace = () => {
      const km = val('distance_km'), secs = val('dur_h') * 3600 + val('dur_m') * 60 + val('dur_s');
      if (!km || !secs) { paceOut.textContent = '—'; return; }
      const p = Math.round(secs / km);
      paceOut.textContent = `${Math.floor(p / 60)}:${String(p % 60).padStart(2, '0')} /km`;
    };
    ['distance_km', 'dur_h', 'dur_m', 'dur_s'].forEach(n => form.elements[n]?.addEventListener('input', updatePace));
    updatePace();
  }

  // ---------------- Profile: suggest weekly km from goal ----------------
  const kmInput = document.getElementById('km-goal');
  if (kmInput) {
    const setHint = () => {
      const g = document.querySelector('#profile-form input[name="goal_type"]:checked');
      if (g) kmInput.placeholder = `Auto: ${g.dataset.km} km`;
    };
    document.querySelectorAll('#profile-form input[name="goal_type"]').forEach(r => r.addEventListener('change', setHint));
    setHint();
  }

  // ---------------- Progress dialogs ----------------
  document.querySelectorAll('[data-dialog]').forEach(b =>
    b.addEventListener('click', () => document.getElementById(b.dataset.dialog)?.showModal()));
  document.querySelectorAll('dialog.pdialog').forEach(d =>
    d.addEventListener('click', e => { if (e.target === d) d.close(); }));   // click backdrop to close

  // ---------------- Confirm destructive actions ----------------
  document.querySelectorAll('form[data-confirm]').forEach(f =>
    f.addEventListener('submit', e => { if (!confirm(f.dataset.confirm)) e.preventDefault(); }));
});
