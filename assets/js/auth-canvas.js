// Shared canvas background animation for auth pages
document.addEventListener('DOMContentLoaded', function () {

(function(orbConfig) {
  const canvas = document.getElementById('bgCanvas');
  if (!canvas) return; 

  const ctx = canvas.getContext('2d');
  let W, H, particles = [];

  function resize() {
    W = canvas.width  = window.innerWidth;
    H = canvas.height = window.innerHeight;
  }
  resize();
  window.addEventListener('resize', resize);

  for (let i = 0; i < 45; i++) {
    particles.push({
      x: Math.random() * 1920, y: Math.random() * 1080,
      r: Math.random() * 1.4 + 0.3,
      vx: (Math.random() - 0.5) * 0.25,
      vy: (Math.random() - 0.5) * 0.25,
      alpha: Math.random() * 0.4 + 0.1
    });
  }

  function draw() {
    ctx.clearRect(0, 0, W, H);
    ctx.fillStyle = '#F8FAFC';
    ctx.fillRect(0, 0, W, H);

    orbConfig.forEach(function(o) {
      const g = ctx.createRadialGradient(o.x*W, o.y*H, 0, o.x*W, o.y*H, o.r*Math.max(W,H));
      g.addColorStop(0, o.color);
      g.addColorStop(1, 'transparent');
      ctx.fillStyle = g;
      ctx.fillRect(0, 0, W, H);
    });

    for (let i = 0; i < particles.length; i++) {
      for (let j = i+1; j < particles.length; j++) {
        const dx = particles[i].x - particles[j].x;
        const dy = particles[i].y - particles[j].y;
        const d  = Math.sqrt(dx*dx + dy*dy);
        if (d < 150) {
          ctx.beginPath();
          ctx.moveTo(particles[i].x, particles[i].y);
          ctx.lineTo(particles[j].x, particles[j].y);
          ctx.strokeStyle = 'rgba(37,99,235,' + (0.06*(1-d/150)) + ')';
          ctx.lineWidth = 0.5;
          ctx.stroke();
        }
      }
    }

    particles.forEach(function(p) {
      p.x += p.vx; p.y += p.vy;
      if (p.x < 0) p.x = W; if (p.x > W) p.x = 0;
      if (p.y < 0) p.y = H; if (p.y > H) p.y = 0;
      ctx.beginPath();
      ctx.arc(p.x, p.y, p.r, 0, Math.PI*2);
      ctx.fillStyle = 'rgba(37,99,235,' + p.alpha + ')';
      ctx.fill();
    });

    requestAnimationFrame(draw);
  }
  draw();

})(window.AUTH_ORBS || []);

});