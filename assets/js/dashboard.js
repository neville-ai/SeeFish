// Minimal dashboard JavaScript for SeeFish
// Handles mobile sidebar toggle and minimal interactions

const app = document.querySelector('.db-wrap');

// Mobile sidebar toggle
const sidebar = document.querySelector('.db-sidebar');
const main = document.querySelector('.db-main');

if (sidebar && main) {
  const toggleBtn = document.createElement('button');
  toggleBtn.className = 'db-nav-item logout-btn mobile-toggle';
  toggleBtn.innerHTML = '<i class="fa-solid fa-bars"></i> Navigasi';
  toggleBtn.style.display = 'none';
  toggleBtn.style.position = 'fixed';
  toggleBtn.style.bottom = '20px';
  toggleBtn.style.left = '20px';
  toggleBtn.style.zIndex = '100';
  toggleBtn.style.padding = '10px 15px';
  
  // Only show on mobile (small screens)
  const mediaQuery = window.matchMedia('(max-width: 768px)');
  
  function handleToggle() {
    if (mediaQuery.matches) {
      toggleBtn.style.display = 'block';
    } else {
      toggleBtn.style.display = 'none';
    }
  }
  
  handleToggle();
  mediaQuery.addListener(handleToggle);
  
  document.body.appendChild(toggleBtn);
  
  toggleBtn.addEventListener('click', function(e) {
    e.stopPropagation();
    sidebar.classList.toggle('show');
    main.classList.toggle('expanded');
  });
  
  // Close sidebar when clicking outside on mobile
  document.addEventListener('click', function(e) {
    if (!sidebar.contains(e.target) && !toggleBtn.contains(e.target) && mediaQuery.matches) {
      sidebar.classList.remove('show');
    }
  });
}

// Future enhancements can be added here