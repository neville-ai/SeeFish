// Register page orb positions
window.AUTH_ORBS = [
  { x: 0.85, y: 0.2, r: 0.35, color: 'rgba(37,99,235,0.25)'  },
  { x: 0.1,  y: 0.8, r: 0.3,  color: 'rgba(30,58,138,0.18)' },
];

function togglePassword(id, icon) {
  const input = document.getElementById(id);
  if (input.type === 'password') {
    input.type = 'text';
    icon.classList.replace('fa-eye', 'fa-eye-slash');
  } else {
    input.type = 'password';
    icon.classList.replace('fa-eye-slash', 'fa-eye');
  }
}

function checkStrength(val) {
  const bar  = document.getElementById('strengthBar');
  const hint = document.getElementById('strengthHint');
  let score  = 0;

  if (val.length >= 8)           score++;
  if (val.length >= 12)          score++;
  if (/[A-Z]/.test(val))         score++;
  if (/[0-9]/.test(val))         score++;
  if (/[^A-Za-z0-9]/.test(val)) score++;

  const levels = [
    { w: '0%',   c: 'transparent', t: '' },
    { w: '25%',  c: '#DC2626',     t: 'Lemah' },
    { w: '50%',  c: '#D97706',     t: 'Cukup' },
    { w: '75%',  c: '#2563EB',     t: 'Kuat' },
    { w: '100%', c: '#1E3A8A',     t: 'Sangat kuat' },
  ];

  const lvl = Math.min(score, 4);

  if (bar) {
    bar.style.width      = levels[lvl].w;
    bar.style.background = levels[lvl].c;
  }

  if (hint) {
    hint.textContent = val.length > 0 ? levels[lvl].t : '';
    hint.style.color = levels[lvl].c;
  }
}

function checkMatch() {
  const passEl    = document.getElementById('password');
  const confirmEl = document.getElementById('confirm');
  const hint      = document.getElementById('matchHint');

  if (!passEl || !confirmEl || !hint) return;

  const pass    = passEl.value;
  const confirm = confirmEl.value;

  if (confirm.length === 0) {
    hint.textContent = '';
    return;
  }

  if (pass === confirm) {
    hint.textContent = '\u2713 Password cocok';
    hint.style.color = '#2563EB';
  } else {
    hint.textContent = '\u2715 Password tidak cocok';
    hint.style.color = '#DC2626';
  }
}

document.addEventListener('DOMContentLoaded', function () {

  // toggle password untuk semua icon
  document.querySelectorAll('.auth-eye').forEach(function (eye) {
    eye.addEventListener('click', function () {
      const targetId = this.getAttribute('data-target');
      if (targetId) {
        togglePassword(targetId, this);
      }
    });
  });

  // input password ? strength + match
  const password = document.getElementById('password');
  if (password) {
    password.addEventListener('input', function () {
      checkStrength(this.value);
      checkMatch();
    });
  }

  // input confirm ? match
  const confirm = document.getElementById('confirm');
  if (confirm) {
    confirm.addEventListener('input', function () {
      checkMatch();
    });
  }

  document.querySelectorAll('form').forEach(function (form) {
    form.addEventListener('submit', function () {
      const btn = form.querySelector('button[type="submit"]');
      if (btn) {
        btn.disabled = true;
      }
    });
  });

});