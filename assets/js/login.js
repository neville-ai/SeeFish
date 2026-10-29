// Login page orb positions
window.AUTH_ORBS = [
  { x: 0.1,  y: 0.2, r: 0.35, color: 'rgba(37,99,235,0.25)'  },
  { x: 0.85, y: 0.7, r: 0.3,  color: 'rgba(30,58,138,0.18)' },
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

document.addEventListener('DOMContentLoaded', function () {
  const eye = document.querySelector('.auth-eye');
  const input = document.getElementById('password');

  if (eye && input) {
    eye.addEventListener('click', function () {
      togglePassword('password', this);
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