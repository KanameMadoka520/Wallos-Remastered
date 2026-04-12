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
  let canvasWidth = 0;
  let canvasHeight = 0;
  let ctx = null;
  let rafId = 0;
  let cometTimer = 0;
  let lastFrame = 0;
  let floatParticles = [];
  let comets = [];
  let initialized = false;
  let running = false;

  function random(min, max) {
    return min + Math.random() * (max - min);
  }

  function pick(list) {
    return list[Math.floor(Math.random() * list.length)];
  }

  function superFormula(theta, m, n1, n2, n3) {
    const t1 = Math.pow(Math.abs(Math.cos((m * theta) / 4)), n2);
    const t2 = Math.pow(Math.abs(Math.sin((m * theta) / 4)), n3);
    const value = Math.pow(t1 + t2, -1 / n1);
    return Number.isFinite(value) ? value : 0;
  }

  function resolveElements() {
    body = document.body;
    background = document.querySelector('.wallos-decorative-background');
    flowCanvas = background ? background.querySelector('.wallos-bg-flow') : null;
    floatLayer = background ? background.querySelector('.wallos-bg-float-layer') : null;
    ctx = flowCanvas ? flowCanvas.getContext('2d') : null;
    initialized = !!(body && background && flowCanvas && floatLayer && ctx);
    return initialized;
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
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }

  function drawRibbon(now, index, color, width, alpha) {
    const centerY = canvasHeight * (0.18 + (index * 0.14));
    const amp = canvasHeight * (0.045 + (index * 0.008));
    const phase = now * (0.00012 + (index * 0.000018));

    ctx.beginPath();
    ctx.lineWidth = width;
    ctx.strokeStyle = color.replace('__ALPHA__', alpha.toFixed(3));

    for (let x = -80; x <= canvasWidth + 80; x += 10) {
      const nx = (x / canvasWidth) * Math.PI * 2;
      const y = centerY
        + (amp * Math.sin((nx * 1.1) + phase))
        + ((amp * 0.46) * Math.sin((nx * 2.8) - (phase * 1.36)))
        + ((amp * 0.22) * Math.cos((nx * 5.4) + (phase * 0.72)));

      if (x === -80) {
        ctx.moveTo(x, y);
      } else {
        ctx.lineTo(x, y);
      }
    }

    ctx.stroke();
  }

  function drawLissajous(now, cx, cy, ax, ay, a, b, delta, strokeStyle, lineWidth) {
    const phase = now * 0.00016;
    ctx.beginPath();
    ctx.lineWidth = lineWidth;
    ctx.strokeStyle = strokeStyle;

    for (let step = 0; step <= 300; step += 1) {
      const t = (step / 300) * Math.PI * 2;
      const x = cx + (ax * Math.sin((a * t) + phase + delta)) + ((ax * 0.08) * Math.sin((a * 3 * t) - phase));
      const y = cy + (ay * Math.sin((b * t) + phase * 0.82));

      if (step === 0) {
        ctx.moveTo(x, y);
      } else {
        ctx.lineTo(x, y);
      }
    }

    ctx.stroke();
  }

  function drawSuperShape(now, cx, cy, radius, options) {
    const phase = now * (options.phaseSpeed || 0.00008);
    ctx.beginPath();
    ctx.lineWidth = options.lineWidth || 1;
    ctx.strokeStyle = options.stroke;

    for (let step = 0; step <= 360; step += 1) {
      const theta = ((step / 360) * Math.PI * 2) + phase;
      const r = radius * superFormula(theta, options.m, options.n1, options.n2, options.n3);
      const x = cx + (r * Math.cos(theta) * (options.scaleX || 1));
      const y = cy + (r * Math.sin(theta) * (options.scaleY || 1));

      if (step === 0) {
        ctx.moveTo(x, y);
      } else {
        ctx.lineTo(x, y);
      }
    }

    if (options.closePath !== false) {
      ctx.closePath();
    }

    ctx.stroke();
  }

  function drawHarmonicMesh(now) {
    ctx.save();
    ctx.globalCompositeOperation = 'multiply';

    drawRibbon(now, 0, 'rgba(201, 74, 42, __ALPHA__)', 1.35, 0.14);
    drawRibbon(now, 1, 'rgba(0, 123, 255, __ALPHA__)', 1.05, 0.09);
    drawRibbon(now, 2, 'rgba(26, 26, 26, __ALPHA__)', 0.85, 0.08);

    ctx.shadowBlur = 10;
    ctx.shadowColor = 'rgba(201, 74, 42, 0.10)';
    drawLissajous(
      now,
      canvasWidth * 0.78,
      canvasHeight * 0.22,
      canvasWidth * 0.11,
      canvasHeight * 0.09,
      3,
      2,
      Math.PI / 4,
      'rgba(201, 74, 42, 0.16)',
      1.15
    );

    ctx.shadowColor = 'rgba(0, 123, 255, 0.08)';
    drawLissajous(
      now,
      canvasWidth * 0.24,
      canvasHeight * 0.72,
      canvasWidth * 0.08,
      canvasHeight * 0.12,
      5,
      4,
      Math.PI / 8,
      'rgba(26, 26, 26, 0.12)',
      0.95
    );

    drawSuperShape(now, canvasWidth * 0.58, canvasHeight * 0.52, Math.min(canvasWidth, canvasHeight) * 0.12, {
      m: 7,
      n1: 0.42,
      n2: 1.2,
      n3: 1.2,
      scaleX: 1.38,
      scaleY: 0.78,
      stroke: 'rgba(0, 123, 255, 0.10)',
      lineWidth: 0.9,
      phaseSpeed: 0.00005,
    });

    drawSuperShape(now, canvasWidth * 0.18, canvasHeight * 0.74, Math.min(canvasWidth, canvasHeight) * 0.075, {
      m: 5,
      n1: 0.28,
      n2: 1.5,
      n3: 1.7,
      scaleX: 1.2,
      scaleY: 1.0,
      stroke: 'rgba(201, 74, 42, 0.13)',
      lineWidth: 1.0,
      phaseSpeed: 0.00007,
    });

    drawSuperShape(now, canvasWidth * 0.84, canvasHeight * 0.68, Math.min(canvasWidth, canvasHeight) * 0.055, {
      m: 9,
      n1: 0.55,
      n2: 0.9,
      n3: 1.6,
      scaleX: 1.3,
      scaleY: 1.16,
      stroke: 'rgba(26, 26, 26, 0.08)',
      lineWidth: 0.75,
      phaseSpeed: 0.00004,
    });

    ctx.restore();
  }

  function drawComet(now, comet) {
    const elapsed = now - comet.startTime;
    const progress = elapsed / comet.duration;

    if (progress >= 1) {
      comet.done = true;
      return;
    }

    const trailSteps = 13;
    const ease = 1 - Math.pow(1 - progress, 2.35);

    ctx.save();
    ctx.lineCap = 'round';

    for (let step = trailSteps; step >= 0; step -= 1) {
      const offset = step / trailSteps;
      const t = Math.max(0, ease - (offset * 0.065));
      const tailOpacity = Math.pow(1 - offset, 1.65) * comet.opacity;
      const position = comet.positionAt(t);
      const previous = comet.positionAt(Math.max(0, t - 0.028));

      ctx.beginPath();
      ctx.moveTo(previous.x, previous.y);
      ctx.lineTo(position.x, position.y);
      ctx.strokeStyle = 'rgba(' + comet.color + ', ' + tailOpacity.toFixed(3) + ')';
      ctx.lineWidth = comet.width * (1 - (offset * 0.72));
      ctx.stroke();
    }

    const head = comet.positionAt(ease);
    const headGlow = 8 + (Math.sin((progress * Math.PI)) * 5);

    ctx.beginPath();
    ctx.fillStyle = 'rgba(255, 255, 255, ' + (comet.opacity * 0.95).toFixed(3) + ')';
    ctx.shadowBlur = 18;
    ctx.shadowColor = 'rgba(' + comet.color + ', 0.35)';
    ctx.arc(head.x, head.y, headGlow * 0.22, 0, Math.PI * 2);
    ctx.fill();

    ctx.restore();
  }

  function createComet() {
    const startX = random(canvasWidth * 0.08, canvasWidth * 0.44);
    const startY = random(canvasHeight * 0.08, canvasHeight * 0.42);
    const travel = random(canvasWidth * 0.18, canvasWidth * 0.34);
    const slope = random(0.18, 0.42);
    const curve = random(26, 74);
    const wobble = random(8, 24);
    const phase = random(0, Math.PI * 2);

    return {
      startTime: performance.now(),
      duration: random(2200, 3600),
      width: random(2.4, 3.8),
      opacity: random(0.24, 0.38),
      color: Math.random() > 0.55 ? '201, 74, 42' : '245, 245, 245',
      positionAt: function (t) {
        const x = startX + (travel * t) + (Math.sin((t * Math.PI) + phase) * wobble);
        const y = startY + ((travel * slope) * t) - (Math.sin(t * Math.PI) * curve);
        return { x: x, y: y };
      },
      done: false,
    };
  }

  function scheduleComet() {
    if (reduceMotion || !running || body.classList.contains('decorative-background-disabled')) {
      return;
    }

    const delay = random(window.innerWidth < 768 ? 5200 : 3200, window.innerWidth < 768 ? 9200 : 6800);
    window.clearTimeout(cometTimer);
    cometTimer = window.setTimeout(function () {
      comets.push(createComet());
      scheduleComet();
    }, delay);
  }

  function createParticle() {
    const element = document.createElement('span');
    const isIcon = Math.random() < 0.16;
    element.className = 'wallos-bg-float ' + (isIcon ? 'is-icon' : 'is-text');

    if (Math.random() < 0.28) {
      element.classList.add('is-accent');
    } else if (Math.random() < 0.5) {
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
      baseX: random(4, 96),
      baseY: random(8, 92),
      amplitudeX: random(6, 24),
      amplitudeY: random(8, 28),
      speed: random(0.0004, 0.0011),
      phase: random(0, Math.PI * 2),
      rotate: random(-11, 11),
      scale: isIcon ? random(0.72, 1.04) : random(0.8, 1.02),
      opacity: isIcon ? random(0.04, 0.11) : random(0.03, 0.09),
      size: isIcon ? random(14, 22) : random(10, 15),
    };

    particle.element.style.fontSize = particle.size + 'px';
    particle.element.style.opacity = particle.opacity.toFixed(3);

    return particle;
  }

  function ensureParticles() {
    if (!floatLayer) {
      return;
    }

    const target = window.innerWidth < 768 ? 12 : 26;

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
      const driftX = Math.sin((now * particle.speed) + particle.phase + (index * 0.1)) * particle.amplitudeX;
      const driftY = Math.cos((now * particle.speed * 1.16) + particle.phase) * particle.amplitudeY;
      const rotate = particle.rotate + (Math.sin((now * particle.speed * 0.8) + particle.phase) * 4.5);
      const scale = particle.scale + (Math.cos((now * particle.speed) + particle.phase) * 0.04);

      particle.element.style.left = 'calc(' + particle.baseX.toFixed(2) + '% + ' + driftX.toFixed(2) + 'px)';
      particle.element.style.top = 'calc(' + particle.baseY.toFixed(2) + '% + ' + driftY.toFixed(2) + 'px)';
      particle.element.style.transform = 'translate(-50%, -50%) rotate(' + rotate.toFixed(2) + 'deg) scale(' + scale.toFixed(3) + ')';
    });
  }

  function drawScene(now) {
    if (!ctx) {
      return;
    }

    ctx.clearRect(0, 0, canvasWidth, canvasHeight);
    drawHarmonicMesh(now);

    comets = comets.filter(function (comet) {
      drawComet(now, comet);
      return !comet.done;
    });
  }

  function frame(now) {
    if (!running) {
      return;
    }

    if (reduceMotion) {
      drawScene(0);
      renderParticles(0);
      return;
    }

    if (now - lastFrame < 16) {
      rafId = window.requestAnimationFrame(frame);
      return;
    }

    lastFrame = now;
    drawScene(now);
    renderParticles(now);
    rafId = window.requestAnimationFrame(frame);
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
    scheduleComet();

    if (rafId) {
      window.cancelAnimationFrame(rafId);
    }

    rafId = window.requestAnimationFrame(frame);
  }

  function stop() {
    running = false;
    window.clearTimeout(cometTimer);

    if (rafId) {
      window.cancelAnimationFrame(rafId);
      rafId = 0;
    }

    comets = [];

    if (ctx) {
      ctx.clearRect(0, 0, canvasWidth, canvasHeight);
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
    if (!running && !(body && body.classList.contains('decorative-background-enabled'))) {
      return;
    }

    initializeScene();
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', refresh, { once: true });
  } else {
    refresh();
  }
})();
