// ---- ANIMATED PARTICLE / GRID BACKGROUND ----
const canvas = document.getElementById('bgCanvas');
const ctx    = canvas.getContext('2d');

let W, H, particles = [];

function resize(){
  W = canvas.width  = window.innerWidth;
  H = canvas.height = window.innerHeight;
}
resize();
window.addEventListener('resize', resize);

// Create particles
for(let i = 0; i < 55; i++){
  particles.push({
    x: Math.random() * 1920,
    y: Math.random() * 1080,
    r: Math.random() * 1.5 + 0.3,
    vx: (Math.random() - 0.5) * 0.3,
    vy: (Math.random() - 0.5) * 0.3,
    alpha: Math.random() * 0.5 + 0.1
  });
}

// Gradient orbs
const orbs = [
  { x: 0.15, y: 0.3,  r: 0.35, color: 'rgba(37,99,235,0.18)' },
  { x: 0.75, y: 0.6,  r: 0.3,  color: 'rgba(30,58,138,0.14)' },
  { x: 0.5,  y: 0.85, r: 0.25, color: 'rgba(37,99,235,0.10)' },
];

function draw(){
  ctx.clearRect(0, 0, W, H);

  // Background
  ctx.fillStyle = '#F8FAFC';
  ctx.fillRect(0, 0, W, H);

  // Orbs
  orbs.forEach(o => {
    const grd = ctx.createRadialGradient(o.x*W, o.y*H, 0, o.x*W, o.y*H, o.r*Math.max(W,H));
    grd.addColorStop(0, o.color);
    grd.addColorStop(1, 'transparent');
    ctx.fillStyle = grd;
    ctx.fillRect(0, 0, W, H);
  });

  // Connection lines between close particles
  for(let i = 0; i < particles.length; i++){
    for(let j = i+1; j < particles.length; j++){
      const dx = particles[i].x - particles[j].x;
      const dy = particles[i].y - particles[j].y;
      const dist = Math.sqrt(dx*dx + dy*dy);
      if(dist < 160){
        ctx.beginPath();
        ctx.moveTo(particles[i].x, particles[i].y);
        ctx.lineTo(particles[j].x, particles[j].y);
        ctx.strokeStyle = `rgba(37,99,235,${0.06 * (1 - dist/160)})`;
        ctx.lineWidth = 0.5;
        ctx.stroke();
      }
    }
  }

  // Dots
  particles.forEach(p => {
    p.x += p.vx; p.y += p.vy;
    if(p.x < 0) p.x = W;
    if(p.x > W) p.x = 0;
    if(p.y < 0) p.y = H;
    if(p.y > H) p.y = 0;

    ctx.beginPath();
    ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
    ctx.fillStyle = `rgba(37,99,235,${p.alpha})`;
    ctx.fill();
  });

  requestAnimationFrame(draw);
}
draw();