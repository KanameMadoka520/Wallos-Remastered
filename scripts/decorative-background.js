(function () {
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const dpr = Math.min(window.devicePixelRatio || 1, 2);

  const tokenPool = [
    'INIT', 'SYNC', 'LOAD', 'EXEC', 'PING', 'ACK', 'TLS', 'DNS', 'CDN', 'BGP',
    'TCP', 'UDP', 'NULL', 'TRUE', 'FALSE', 'UTF-8', '0xC94A2A', '0x1A1A1A',
    '127.0.0.1', 'TPS:20.0', 'MSPT:12', 'CHUNK', 'BUFFER', 'VECTOR', '订阅',
    '月付', '年付', '账单', '支付', '云端', '路由', '节点', 'Δ', 'Σ', 'Ω',
    '{ }', '</>', '&&', '||', '::', '¥', '$'
  ];

  const iconPool = [
    'fa-brands fa-cc-visa',
    'fa-brands fa-cc-mastercard',
    'fa-brands fa-paypal',
    'fa-brands fa-alipay',
    'fa-brands fa-weixin',
    'fa-brands fa-bitcoin',
    'fa-brands fa-apple-pay'
  ];

  let body = null;
  let background = null;
  let flowCanvas = null;
  let floatLayer = null;
  let meteorLayer = null;
  let canvasWidth = 0;
  let canvasHeight = 0;
  let ctx = null;
  let rafId = 0;
  let lastFrame = 0;
  let meteorTimer = 0;
  let points = [];
  let floatParticles = [];
  let initialized = false;
  let running = false;

  function random(min, max) {
    return min + Math.random() * (max - min);
  }

  function pick(list) {
    return list[Math.floor(Math.random() * list.length)];
  }

  function resizeCanvas() {
    if (!flowCanvas || !ctx) {
      return;
    }

    canvasWidth = window.innerWidth;
    canvasHeight = window.innerHeight;
    flowCanvas.width = Math.round(canvasWidth * dpr);
    flowCanvas.height = Math.round(canvasHeight * dpr);
    flowCanvas.style.width = canvasWidth + 'px';
    flowCanvas.style.height = canvasHeight + 'px';
    ctx = flowCanvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

    const pointCount = canvasWidth < 768 ? 18 : 30;
    points = Array.from({ length: pointCount }, function () {
      return {
        x: random(0, canvasWidth),
        y: random(0, canvasHeight),
        vx: random(-0.18, 0.18),
        vy: random(-0.18, 0.18),
        radius: random(1.2, 2.6)
      };
    });
  }

  function drawFlowField(now) {
    if (!ctx) {
      return;
    }

    ctx.clearRect(0, 0, canvasWidth, canvasHeight);

    const waveY = canvasHeight * 0.18;
    const waveAmplitude = canvasHeight * 0.06;
    const waveTime = now * 0.00018;

    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';

    for (let layer = 0; layer < 4; layer += 1) {
      const offset = layer * 0.14;
      const alpha = layer === 0 ? 0.15 : 0.07;
      ctx.beginPath();
      ctx.strokeStyle = layer % 2 === 0
        ? 'rgba(201, 74, 42, ' + alpha + ')'
        : 'rgba(0, 123, 255, ' + (alpha * 0.7) + ')';
      ctx.lineWidth = layer === 0 ? 1.3 : 0.8;

      for (let x = -120; x <= canvasWidth + 120; x += 28) {
        const y = waveY + layer * 95 + Math.sin((x * 0.008) + waveTime + offset) * waveAmplitude;
        const secondary = Math.cos((x * 0.0038) - waveTime * 0.9 - offset) * (waveAmplitude * 0.38);
        if (x === -120) {
          ctx.moveTo(x, y + secondary);
        } else {
          ctx.lineTo(x, y + secondary);
        }
      }
      ctx.stroke();
    }

    points.forEach(function (point) {
      point.x += point.vx;
      point.y += point.vy;

      if (point.x < -40) point.x = canvasWidth + 40;
      if (point.x > canvasWidth + 40) point.x = -40;
      if (point.y < -40) point.y = canvasHeight + 40;
      if (point.y > canvasHeight + 40) point.y = -40;
    });

    for (let i = 0; i < points.length; i += 1) {
      for (let j = i + 1; j < points.length; j += 1) {
        const a = points[i];
        const b = points[j];
        const dx = a.x - b.x;
        const dy = a.y - b.y;
        const distance = Math.sqrt((dx * dx) + (dy * dy));

        if (distance > 220) {
          continue;
        }

        const opacity = (1 - (distance / 220)) * 0.18;
        ctx.beginPath();
        ctx.moveTo(a.x, a.y);
        ctx.lineTo(b.x, b.y);
        ctx.strokeStyle = 'rgba(26, 26, 26, ' + opacity.toFixed(3) + ')';
        ctx.lineWidth = 0.75;
        ctx.stroke();
      }
    }

    points.forEach(function (point, index) {
      ctx.beginPath();
      ctx.arc(point.x, point.y, point.radius, 0, Math.PI * 2);
      ctx.fillStyle = index % 4 === 0 ? 'rgba(201, 74, 42, 0.36)' : 'rgba(26, 26, 26, 0.14)';
      ctx.fill();
    });
  }

  function createParticle() {
    const element = document.createElement('span');
    const isIcon = Math.random() < 0.22;
    element.className = 'wallos-bg-float ' + (isIcon ? 'is-icon' : 'is-text');

    if (Math.random() < 0.35) {
      element.classList.add('is-accent');
    } else if (Math.random() < 0.45) {
      element.classList.add('is-dim');
    }

    if (isIcon) {
      const icon = document.createElement('i');
      icon.className = pick(iconPool);
      element.appendChild(icon);
    } else {
      element.textContent = pick(tokenPool);
    }

    floatLayer.appendChild(element);

    const particle = {
      element: element,
      baseX: random(2, 98),
      baseY: random(4, 96),
      amplitudeX: random(8, 42),
      amplitudeY: random(12, 56),
      speed: random(0.0006, 0.0018),
      phase: random(0, Math.PI * 2),
      rotate: random(-18, 18),
      scale: isIcon ? random(0.7, 1.2) : random(0.78, 1.14),
      opacity: isIcon ? random(0.08, 0.18) : random(0.05, 0.14),
      size: isIcon ? random(16, 30) : random(11, 18)
    };

    particle.element.style.fontSize = particle.size + 'px';
    particle.element.style.opacity = particle.opacity.toFixed(3);

    return particle;
  }

  function ensureParticles() {
    if (!floatLayer) {
      return;
    }

    const target = window.innerWidth < 768 ? 20 : 44;

    while (floatParticles.length < target) {
      floatParticles.push(createParticle());
    }

    while (floatParticles.length > target) {
      const particle = floatParticles.pop();
      if (particle && particle.element && particle.element.parentNode) {
        particle.element.parentNode.removeChild(particle.element);
      }
    }
  }

  function renderParticles(now) {
    floatParticles.forEach(function (particle, index) {
      const driftX = Math.sin((now * particle.speed) + particle.phase + (index * 0.18)) * particle.amplitudeX;
      const driftY = Math.cos((now * particle.speed * 1.35) + particle.phase) * particle.amplitudeY;
      const rotate = particle.rotate + Math.sin((now * particle.speed * 0.9) + particle.phase) * 8;
      const scale = particle.scale + (Math.cos((now * particle.speed * 1.2) + particle.phase) * 0.08);

      particle.element.style.left = 'calc(' + particle.baseX.toFixed(2) + '% + ' + driftX.toFixed(2) + 'px)';
      particle.element.style.top = 'calc(' + particle.baseY.toFixed(2) + '% + ' + driftY.toFixed(2) + 'px)';
      particle.element.style.transform = 'translate(-50%, -50%) rotate(' + rotate.toFixed(2) + 'deg) scale(' + scale.toFixed(3) + ')';
    });
  }

  function scheduleMeteor() {
    if (reduceMotion || !meteorLayer || !running || body.classList.contains('decorative-background-disabled')) {
      return;
    }

    const delay = random(window.innerWidth < 768 ? 900 : 280, window.innerWidth < 768 ? 2200 : 1100);
    window.clearTimeout(meteorTimer);
    meteorTimer = window.setTimeout(function () {
      spawnMeteor();
      scheduleMeteor();
    }, delay);
  }

  function spawnMeteor() {
    if (!meteorLayer || !running) {
      return;
    }

    const meteor = document.createElement('span');
    meteor.className = 'wallos-bg-meteor';

    const startX = random(window.innerWidth * 0.1, window.innerWidth * 0.92);
    const startY = random(-40, window.innerHeight * 0.45);
    const angle = random(18, 42);
    const length = random(window.innerWidth < 768 ? 90 : 140, window.innerWidth < 768 ? 180 : 260);
    const duration = random(0.8, 1.8);
    const travelX = random(window.innerWidth < 768 ? 120 : 240, window.innerWidth < 768 ? 260 : 460);
    const travelY = travelX * Math.tan((angle * Math.PI) / 180) * 0.45;

    meteor.style.left = startX.toFixed(2) + 'px';
    meteor.style.top = startY.toFixed(2) + 'px';
    meteor.style.setProperty('--meteor-angle', angle.toFixed(2) + 'deg');
    meteor.style.setProperty('--meteor-length', length.toFixed(2) + 'px');
    meteor.style.setProperty('--meteor-duration', duration.toFixed(2) + 's');
    meteor.style.setProperty('--meteor-travel-x', travelX.toFixed(2) + 'px');
    meteor.style.setProperty('--meteor-travel-y', travelY.toFixed(2) + 'px');

    meteorLayer.appendChild(meteor);
    meteor.addEventListener('animationend', function () {
      meteor.remove();
    }, { once: true });

    if (Math.random() > 0.55) {
      window.setTimeout(spawnMeteor, random(70, 180));
    }
  }

  function frame(now) {
    if (!running) {
      return;
    }

    if (reduceMotion) {
      drawFlowField(0);
      renderParticles(0);
      return;
    }

    if (now - lastFrame < 16) {
      rafId = window.requestAnimationFrame(frame);
      return;
    }

    lastFrame = now;
    drawFlowField(now);
    renderParticles(now);
    rafId = window.requestAnimationFrame(frame);
  }

  function resolveElements() {
    body = document.body;
    background = document.querySelector('.wallos-decorative-background');
    flowCanvas = background ? background.querySelector('.wallos-bg-flow') : null;
    floatLayer = background ? background.querySelector('.wallos-bg-float-layer') : null;
    meteorLayer = background ? background.querySelector('.wallos-bg-meteor-layer') : null;
    ctx = flowCanvas ? flowCanvas.getContext('2d') : null;
    initialized = !!(body && background && flowCanvas && floatLayer && meteorLayer && ctx);
    return initialized;
  }

  function initializeScene() {
    if (!resolveElements()) {
      return false;
    }
    resizeCanvas();
    ensureParticles();
    return true;
  }

  function start() {
    if (!initialized && !initializeScene()) {
      return;
    }

    if (running) {
      return;
    }

    running = true;
    scheduleMeteor();
    if (rafId) {
      window.cancelAnimationFrame(rafId);
    }
    rafId = window.requestAnimationFrame(frame);
  }

  function stop() {
    running = false;
    window.clearTimeout(meteorTimer);
    if (rafId) {
      window.cancelAnimationFrame(rafId);
      rafId = 0;
    }
    if (ctx) {
      ctx.clearRect(0, 0, canvasWidth, canvasHeight);
    }
    if (meteorLayer) {
      meteorLayer.innerHTML = '';
    }
  }

  function refresh() {
    if (!resolveElements()) {
      return;
    }

    if (body.classList.contains('decorative-background-disabled')) {
      stop();
      return;
    }

    if (!initialized) {
      initializeScene();
    }

    start();
  }

  window.WallosDecorativeBackground = {
    refresh: refresh,
    start: start,
    stop: stop,
  };

  window.addEventListener('resize', function () {
    if (!running && !body?.classList.contains('decorative-background-enabled')) {
      return;
    }
    initializeScene();
    if (running) {
      start();
    }
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', refresh, { once: true });
  } else {
    refresh();
  }
})();
