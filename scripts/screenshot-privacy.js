(function (global) {
  "use strict";

  if (!global || !global.document) {
    return;
  }

  const document = global.document;
  const ROOT_CLASS = "wallos-screenshot-privacy-enabled";
  const MASKED_CLASS = "wallos-privacy-masked";
  const FIELD_CLASS = "wallos-privacy-masked-field";
  const REPLACED_CLASS = "wallos-privacy-replaced";
  const ICON_HOST_CLASS = "wallos-privacy-icon-host";
  const GENERATED_ATTRIBUTE = "data-wallos-privacy-generated";
  const SYNC_STORAGE_KEY = "wallos-screenshot-privacy-sync-v1";
  const SYNC_CHANNEL_NAME = "wallos-screenshot-privacy";
  const BLOCKED_SELECTOR = [
    '[data-subscription-action="open-edit-subscription"]',
    '[data-subscription-action="open-add-subscription"]',
    '[data-subscription-action="clone-subscription"]',
    '[data-subscription-action="delete-subscription"]',
    '[data-subscription-action="renew-subscription"]',
    '[data-subscription-action="restore-from-recycle-bin"]',
    '[data-subscription-action="permanently-delete-subscription"]',
    '[data-subscription-action="generate-image-variants"]',
    '[data-subscription-action="open-payment-history"]',
    '[data-subscription-action="open-payment-modal"]',
    '[data-subscription-action="open-pages-manager"]',
    '[data-subscription-action="export-payment-history"]',
    '[data-subscription-action="export-subscription-calendar"]',
    '[data-subscription-action="edit-payment-record"]',
    '[data-subscription-action="open-subscription-image-viewer"]',
    '[data-subscription-action="open-image-original"]',
    '[data-subscription-action="download-image"]',
    "#subscription-payment-history-add-button",
    "#details-export-button",
    "#details-url-button",
    "#export-json",
    "#export-csv",
    "#export-uploaded-images",
    '[onclick*="showExportPopup"]',
  ].join(",");

  const englishDefaults = {
    names: [
      "Aurora Cloud", "Bluebird Studio", "Cedar Notes", "Comet Music",
      "Harbor Plus", "Juniper Media", "Lighthouse Tools", "Maple Play",
      "Nimbus Library", "Orbit Workspace", "Pebble Box", "Silverline Service",
    ],
    descriptions: [
      "Demo description for a private subscription screenshot.",
      "Sample plan with fictional benefits and placeholder details.",
      "Masked service notes; the original description is unchanged.",
      "Illustrative membership information generated for sharing.",
    ],
    aiTitles: ["Review a sample plan", "Try a fictional bundle", "Demo saving suggestion"],
    currency: "$",
    category: "Demo Category",
    payer: "Demo Member",
    paymentMethod: "Demo Wallet",
    date: "Aug 18, 2027",
    chartSubtitle: "Synthetic chart data",
    metricFormula: "Demo amount = sample price × example period",
    metricSummary: "Values in this explanation are fictional and safe to share.",
    metricItems: "Sample subscription entries are shown while privacy mode is enabled.",
    blockedMessage: "This action is unavailable while screenshot privacy mode is enabled.",
  };

  const chineseDefaults = {
    names: [
      "极光云端", "蓝鸟工坊", "雪松笔记", "彗星音乐",
      "港湾会员", "杜松影音", "灯塔工具箱", "枫叶乐园",
      "云层书库", "星轨空间", "卵石盒子", "银线服务",
    ],
    descriptions: [
      "用于截图展示的虚构订阅说明。",
      "这是一段包含模拟权益的示例描述。",
      "真实备注已隐藏，原始内容没有被修改。",
      "用于分享页面效果的演示会员信息。",
    ],
    aiTitles: ["检查示例套餐", "尝试虚构组合", "演示节省建议"],
    currency: "¥",
    category: "演示分类",
    payer: "演示成员",
    paymentMethod: "演示钱包",
    date: "2027年8月18日",
    chartSubtitle: "图表中为随机演示数据",
    metricFormula: "演示金额 = 随机单价 × 示例周期",
    metricSummary: "该说明中的金额均为可安全分享的虚构数据。",
    metricItems: "脱敏模式已将真实订阅项替换为示例项。",
    blockedMessage: "截图脱敏模式开启时，为避免泄露真实信息，暂不能打开此功能。",
  };

  const textRecords = new Map();
  const attributeRecords = new Map();
  const ownedClasses = new Map();
  const generatedNodes = new Set();
  let observer = null;
  let scanQueued = false;
  let blockedNotice = null;
  let blockedNoticeTimer = 0;
  let syncChannel = null;
  let syncReloading = false;
  let syncMarkerAtLoad = readSyncMarker();
  let runtimeConfig = readRuntimeConfig();
  let enabled = normalizeBoolean(runtimeConfig.enabled);

  function normalizeBoolean(value) {
    if (typeof value === "boolean") return value;
    if (typeof value === "number") return value === 1;
    return ["1", "true", "yes", "on"].includes(String(value || "").trim().toLowerCase());
  }

  function isChineseLanguage() {
    const language = String(global.lang || document.documentElement.lang || navigator.language || "").toLowerCase();
    return language === "zh" || language.startsWith("zh-") || language.startsWith("zh_");
  }

  function cleanString(value, fallback) {
    const normalized = typeof value === "string" ? value.trim() : "";
    return normalized || fallback;
  }

  function cleanPool(value, fallback) {
    if (!Array.isArray(value)) return fallback.slice();
    const normalized = value
      .filter((item) => typeof item === "string" && item.trim() !== "")
      .map((item) => item.trim().slice(0, 80));
    return normalized.length > 0 ? normalized : fallback.slice();
  }

  function normalizeLabels(value) {
    const defaults = isChineseLanguage() ? chineseDefaults : englishDefaults;
    const provided = value && typeof value === "object" && !Array.isArray(value) ? value : {};
    return {
      names: cleanPool(provided.names || provided.subscriptionNames, defaults.names),
      descriptions: cleanPool(provided.descriptions || provided.subscriptionDescriptions, defaults.descriptions),
      aiTitles: cleanPool(provided.aiTitles || provided.ai_titles, defaults.aiTitles),
      currency: cleanString(provided.currency || provided.currencySymbol, defaults.currency).slice(0, 8),
      category: cleanString(provided.category, defaults.category).slice(0, 80),
      payer: cleanString(provided.payer || provided.member, defaults.payer).slice(0, 80),
      paymentMethod: cleanString(provided.paymentMethod || provided.payment_method, defaults.paymentMethod).slice(0, 80),
      date: cleanString(provided.date, defaults.date).slice(0, 80),
      chartSubtitle: cleanString(provided.chartSubtitle || provided.chart_subtitle, defaults.chartSubtitle).slice(0, 160),
      metricFormula: cleanString(provided.metricFormula || provided.metric_formula, defaults.metricFormula).slice(0, 240),
      metricSummary: cleanString(provided.metricSummary || provided.metric_summary, defaults.metricSummary).slice(0, 280),
      metricItems: cleanString(provided.metricItems || provided.metric_items, defaults.metricItems).slice(0, 280),
      blockedMessage: cleanString(provided.blockedMessage || provided.blocked_message, defaults.blockedMessage).slice(0, 320),
    };
  }

  function readRuntimeConfig() {
    const source = global.WallosScreenshotPrivacyConfig;
    const value = source && typeof source === "object" && !Array.isArray(source) ? source : {};
    return {
      enabled: value.enabled,
      seed: cleanString(value.seed, "wallos-remastered-screenshot-privacy"),
      labels: normalizeLabels(value.labels),
      blockedMessage: cleanString(value.blockedMessage, ""),
    };
  }

  function refreshRuntimeConfig() {
    runtimeConfig = readRuntimeConfig();
    return runtimeConfig;
  }

  function readSyncMarker() {
    try {
      return global.localStorage?.getItem(SYNC_STORAGE_KEY) || "";
    } catch (error) {
      return "";
    }
  }

  function reloadForServerPrivacyState() {
    if (syncReloading) return;
    syncReloading = true;
    global.location.reload();
  }

  function handleExternalSyncMarker(marker) {
    const normalizedMarker = typeof marker === "string" ? marker : JSON.stringify(marker || {});
    if (!normalizedMarker || normalizedMarker === syncMarkerAtLoad) return;
    syncMarkerAtLoad = normalizedMarker;
    reloadForServerPrivacyState();
  }

  function handleStorageSync(event) {
    if (event.key !== SYNC_STORAGE_KEY || !event.newValue) return;
    handleExternalSyncMarker(event.newValue);
  }

  function handlePageShowSync(event) {
    const currentMarker = readSyncMarker();
    if (currentMarker && currentMarker !== syncMarkerAtLoad) {
      handleExternalSyncMarker(currentMarker);
      return;
    }

    // The setting endpoint also writes a non-sensitive state cookie. It lets a
    // BFCache page detect a missed cross-tab event without throwing away the
    // fast back/forward path when the privacy state did not actually change.
    if (event.persisted) {
      const cookieMatch = document.cookie.match(/(?:^|;\s*)wallosScreenshotPrivacy=([01])(?:;|$)/);
      if (cookieMatch && normalizeBoolean(cookieMatch[1]) !== enabled) {
        reloadForServerPrivacyState();
      }
    }
  }

  function handleVisibilitySync() {
    if (document.visibilityState !== "visible") return;
    const currentMarker = readSyncMarker();
    if (currentMarker && currentMarker !== syncMarkerAtLoad) {
      handleExternalSyncMarker(currentMarker);
    }
  }

  function startCrossTabSync() {
    if (typeof global.BroadcastChannel === "function") {
      try {
        syncChannel = new global.BroadcastChannel(SYNC_CHANNEL_NAME);
        syncChannel.addEventListener("message", (event) => handleExternalSyncMarker(event.data));
      } catch (error) {
        syncChannel = null;
      }
    }
    global.addEventListener("storage", handleStorageSync);
    global.addEventListener("pageshow", handlePageShowSync);
    document.addEventListener("visibilitychange", handleVisibilitySync);
  }

  function announceServerPrivacyChange(nextEnabled) {
    const payload = {
      enabled: normalizeBoolean(nextEnabled),
      changedAt: Date.now(),
      nonce: Math.random().toString(36).slice(2),
    };
    const marker = JSON.stringify(payload);
    syncMarkerAtLoad = marker;
    try {
      global.localStorage?.setItem(SYNC_STORAGE_KEY, marker);
    } catch (error) {
      // BroadcastChannel still covers active same-origin tabs when storage is unavailable.
    }
    try {
      syncChannel?.postMessage(marker);
    } catch (error) {
      // The current page will reload even if cross-tab notification is unavailable.
    }
  }

  function hashNumber(value) {
    const source = `${runtimeConfig.seed}|${String(value ?? "")}`;
    let hash = 2166136261;
    for (let index = 0; index < source.length; index += 1) {
      hash ^= source.charCodeAt(index);
      hash = Math.imul(hash, 16777619);
    }
    return hash >>> 0;
  }

  function identityValue(value, fallback) {
    if (value && typeof value === "object") {
      for (const key of ["subscription_id", "id", "record_id", "name"]) {
        if (value[key] !== undefined && value[key] !== null && String(value[key]).trim() !== "") {
          return `${key}:${String(value[key]).trim()}`;
        }
      }
      return fallback;
    }
    const normalized = String(value ?? "").trim();
    return normalized || fallback;
  }

  function fakeName(identity, context) {
    const key = `${String(context || "name")}|${identityValue(identity, "anonymous")}`;
    const number = hashNumber(key);
    const pool = runtimeConfig.labels.names;
    const suffix = (number % 4096).toString(16).toUpperCase().padStart(3, "0");
    return `${pool[number % pool.length]} ${suffix}`;
  }

  function fakeDescription(identity, context) {
    const key = `${String(context || "description")}|${identityValue(identity, "anonymous")}`;
    const pool = runtimeConfig.labels.descriptions;
    return pool[hashNumber(key) % pool.length];
  }

  function fakePriceNumber(identity, context) {
    const key = `${String(context || "price")}|${identityValue(identity, "anonymous")}`;
    const number = hashNumber(key);
    const cents = [0, 29, 49, 79, 88, 90, 99][(number >>> 8) % 7];
    return Number((4 + (number % 196) + (cents / 100)).toFixed(2));
  }

  function currencyFromText(value) {
    const match = String(value || "").match(/(?:US\$|HK\$|CA\$|AU\$|NZ\$|S\$|NT\$|[$€£¥₹₽₩₺₫₴₦₱])/u);
    return match ? match[0] : runtimeConfig.labels.currency;
  }

  function fakePrice(identity, context) {
    const number = fakePriceNumber(identity, typeof context === "string" ? context : "price");
    const original = typeof identity === "string" ? identity : "";
    const currency = context && typeof context === "object" && context.currency
      ? cleanString(context.currency, runtimeConfig.labels.currency).slice(0, 8)
      : currencyFromText(original);
    return `${currency}${number.toFixed(2)}`;
  }

  function xmlEscape(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&apos;");
  }

  function fakeIcon(identity) {
    const key = identityValue(identity, "icon");
    const number = hashNumber(`icon|${key}`);
    const palettes = [
      ["#5B8CFF", "#7967FF"], ["#25B99A", "#49D17D"],
      ["#FF7A8A", "#FFAA62"], ["#A868F1", "#DF6DD5"],
      ["#24A6D9", "#55D5E0"], ["#F0A93B", "#F16B5C"],
    ];
    const symbols = ["◆", "▲", "●", "✶", "■", "◈"];
    const palette = palettes[number % palettes.length];
    const symbol = symbols[(number >>> 5) % symbols.length];
    const gradientId = `g${number.toString(16)}`;
    const svg = [
      '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96" role="img">',
      `<defs><linearGradient id="${gradientId}" x1="0" y1="0" x2="1" y2="1">`,
      `<stop stop-color="${palette[0]}"/><stop offset="1" stop-color="${palette[1]}"/>`,
      "</linearGradient></defs>",
      `<rect width="96" height="96" rx="24" fill="url(#${gradientId})"/>`,
      '<circle cx="48" cy="48" r="27" fill="none" stroke="rgba(255,255,255,.42)" stroke-width="3"/>',
      `<text x="48" y="61" text-anchor="middle" font-family="Arial,sans-serif" font-size="38" fill="#fff">${xmlEscape(symbol)}</text>`,
      "</svg>",
    ].join("");
    return `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(svg)}`;
  }

  function sanitizeSubscription(subscription, context) {
    if (!enabled || !subscription || typeof subscription !== "object" || Array.isArray(subscription)) {
      return subscription;
    }

    const copy = { ...subscription };
    const scope = String(context || "display");
    const identity = identityValue(subscription, scope);
    const name = fakeName(identity, scope);
    const description = fakeDescription(identity, scope);
    const icon = fakeIcon(identity);
    const numericPrice = fakePriceNumber(identity, `${scope}:price`);

    copy.name = name;
    if (Object.prototype.hasOwnProperty.call(copy, "subscription_name")) copy.subscription_name = name;
    if (Object.prototype.hasOwnProperty.call(copy, "title")) copy.title = name;
    if (Object.prototype.hasOwnProperty.call(copy, "description")) copy.description = description;
    copy.notes = description;
    if (Object.prototype.hasOwnProperty.call(copy, "notes_html")) copy.notes_html = description;
    if (Object.prototype.hasOwnProperty.call(copy, "price")) copy.price = numericPrice;
    if (Object.prototype.hasOwnProperty.call(copy, "original_price")) {
      copy.original_price = fakePriceNumber(identity, `${scope}:original-price`);
    }
    copy.privacy_icon = icon;
    copy.logo = icon;
    if (Object.prototype.hasOwnProperty.call(copy, "logo_variant")) copy.logo_variant = "";
    if (Object.prototype.hasOwnProperty.call(copy, "payment_method_icon")) copy.payment_method_icon = icon;
    if (Object.prototype.hasOwnProperty.call(copy, "category")) copy.category = runtimeConfig.labels.category;
    if (Object.prototype.hasOwnProperty.call(copy, "category_name")) copy.category_name = runtimeConfig.labels.category;
    if (Object.prototype.hasOwnProperty.call(copy, "payer_user")) copy.payer_user = runtimeConfig.labels.payer;
    if (Object.prototype.hasOwnProperty.call(copy, "payer_name")) copy.payer_name = runtimeConfig.labels.payer;
    if (Object.prototype.hasOwnProperty.call(copy, "payment_method")) copy.payment_method = runtimeConfig.labels.paymentMethod;
    if (Object.prototype.hasOwnProperty.call(copy, "payment_method_name")) copy.payment_method_name = runtimeConfig.labels.paymentMethod;
    for (const key of ["url", "external_url", "detail_image_url", "download_url"]) {
      if (Object.prototype.hasOwnProperty.call(copy, key)) copy[key] = "";
    }

    const hadImages = (Array.isArray(subscription.uploaded_images) && subscription.uploaded_images.length > 0)
      || (Array.isArray(subscription.detail_image_urls) && subscription.detail_image_urls.length > 0)
      || (typeof subscription.detail_image_urls === "string" && !["", "[]"].includes(subscription.detail_image_urls.trim()))
      || Boolean(subscription.detail_image);
    if (Object.prototype.hasOwnProperty.call(copy, "uploaded_images")) {
      copy.uploaded_images = hadImages ? [{
        id: 0,
        file_name: "demo-image.svg",
        original_name: "demo-image.svg",
        access_url: icon,
        thumbnail_url: icon,
        preview_url: icon,
        original_url: icon,
        download_url: "",
        screenshot_privacy_placeholder: true,
      }] : [];
    }
    if (Object.prototype.hasOwnProperty.call(copy, "detail_image_urls")) {
      copy.detail_image_urls = Array.isArray(copy.detail_image_urls) ? [] : "[]";
    }
    if (Object.prototype.hasOwnProperty.call(copy, "detail_image")) copy.detail_image = hadImages ? icon : "";
    if (Array.isArray(copy.payment_records)) copy.payment_records = sanitizeChartData(copy.payment_records, `${scope}:payments`);
    if (Array.isArray(copy.price_rules)) copy.price_rules = sanitizeChartData(copy.price_rules, `${scope}:rules`);
    copy.screenshot_privacy_masked = true;
    return copy;
  }

  function isSensitiveNumericKey(key) {
    return /(?:^|_)(?:y|price|amount|cost|budget|paid|payment|saving|savings|value|total)(?:_|$)/i.test(String(key || ""));
  }

  function sanitizeChartData(value, context, currency) {
    if (!enabled || value === null || value === undefined) return value;
    const seen = new WeakMap();
    const scope = String(context || "chart");

    function visit(current, path, parentKey) {
      if (typeof current === "number") {
        if (parentKey === "data" || parentKey === "y" || parentKey === "value" || isSensitiveNumericKey(parentKey) || Array.isArray(value)) {
          return fakePriceNumber(path, `${scope}:chart-value`);
        }
        return current;
      }
      if (typeof current === "string") {
        if (/^(?:label|name|subscription_name|title)$/i.test(String(parentKey || ""))) {
          return fakeName(path, `${scope}:chart-label`);
        }
        if (/^(?:note|notes|description|summary|rule_summary)$/i.test(String(parentKey || ""))) {
          return fakeDescription(path, `${scope}:chart-description`);
        }
        if (/^(?:url|external_url|download_url)$/i.test(String(parentKey || ""))) {
          return "";
        }
        if (/(?:logo|image)(?:_url|_src|_path)?$/i.test(String(parentKey || ""))) {
          return fakeIcon(path);
        }
        if (isSensitiveNumericKey(parentKey) && /^[-+]?\d+(?:[.,]\d+)?$/.test(current.trim())) {
          return fakePriceNumber(path, `${scope}:chart-value`).toFixed(2);
        }
        return current;
      }
      if (!current || typeof current !== "object") return current;
      if (current instanceof Date) return new Date(current.getTime());
      if (seen.has(current)) return seen.get(current);

      if (Array.isArray(current)) {
        const output = [];
        seen.set(current, output);
        current.forEach((item, index) => {
          if (parentKey === "labels" && typeof item === "string") {
            output[index] = fakeName(`${path}:${index}`, `${scope}:chart-label`);
          } else {
            output[index] = visit(item, `${path}:${index}`, parentKey);
          }
        });
        return output;
      }

      const output = {};
      seen.set(current, output);
      Object.keys(current).forEach((key) => {
        const normalizedKey = key.toLowerCase();
        if (/^(?:backgroundcolor|bordercolor|color|type|fill|tension|borderwidth|hoveroffset)$/.test(normalizedKey)) {
          output[key] = current[key];
          return;
        }
        output[key] = visit(current[key], `${path}.${key}`, normalizedKey);
      });
      return output;
    }

    return visit(value, `${scope}|${String(currency || "")}`, "data");
  }

  function addOwnedClass(element, className) {
    if (!(element instanceof Element) || element.classList.contains(className)) return;
    element.classList.add(className);
    if (!ownedClasses.has(element)) ownedClasses.set(element, new Set());
    ownedClasses.get(element).add(className);
  }

  function originalTextFor(node) {
    const record = textRecords.get(node);
    if (!record) return node.nodeValue || "";
    if ((node.nodeValue || "") !== record.masked) {
      record.original = node.nodeValue || "";
    }
    return record.original;
  }

  function setMaskedTextNode(node, value) {
    if (!node || node.nodeType !== Node.TEXT_NODE) return;
    let record = textRecords.get(node);
    if (!record) {
      record = { original: node.nodeValue || "", masked: null };
      textRecords.set(node, record);
    } else if ((node.nodeValue || "") !== record.masked) {
      record.original = node.nodeValue || "";
    }
    const replacement = String(value ?? "");
    record.masked = replacement;
    if ((node.nodeValue || "") !== replacement) node.nodeValue = replacement;
  }

  function originalElementText(element) {
    if (!(element instanceof Element)) return "";
    const directText = Array.from(element.childNodes)
      .filter((node) => node.nodeType === Node.TEXT_NODE)
      .map((node) => originalTextFor(node))
      .join(" ")
      .trim();
    return directText || element.textContent || "";
  }

  function setMaskedAttribute(element, name, value) {
    if (!(element instanceof Element)) return;
    if (!attributeRecords.has(element)) attributeRecords.set(element, new Map());
    const records = attributeRecords.get(element);
    let record = records.get(name);
    const hasCurrent = element.hasAttribute(name);
    const current = hasCurrent ? element.getAttribute(name) : null;
    if (!record) {
      record = { had: hasCurrent, original: current, masked: null };
      records.set(name, record);
    } else if (current !== record.masked) {
      record.had = hasCurrent;
      record.original = current;
    }

    const replacement = value === null || value === undefined ? null : String(value);
    record.masked = replacement;
    if (replacement === null) {
      if (element.hasAttribute(name)) element.removeAttribute(name);
    } else if (element.getAttribute(name) !== replacement) {
      element.setAttribute(name, replacement);
    }
  }

  function maskDirectText(element, replacement) {
    if (!(element instanceof Element) || element.matches("input,textarea,select,[contenteditable='true']")) return;
    const textNodes = Array.from(element.childNodes).filter((node) => node.nodeType === Node.TEXT_NODE && originalTextFor(node).trim() !== "");
    textNodes.forEach((node, index) => setMaskedTextNode(node, index === 0 ? replacement : ""));
    if (textNodes.length === 0 && element.childElementCount === 0) {
      const node = element.firstChild && element.firstChild.nodeType === Node.TEXT_NODE
        ? element.firstChild
        : document.createTextNode("");
      if (!node.parentNode) element.appendChild(node);
      setMaskedTextNode(node, replacement);
    }
    addOwnedClass(element, FIELD_CLASS);
  }

  function maskTransformedDirectText(element, transform) {
    if (!(element instanceof Element)) return;
    Array.from(element.childNodes)
      .filter((node) => node.nodeType === Node.TEXT_NODE && originalTextFor(node).trim() !== "")
      .forEach((node, index) => setMaskedTextNode(node, transform(originalTextFor(node), index)));
    addOwnedClass(element, FIELD_CLASS);
  }

  function maskRichContent(element, replacement) {
    if (!(element instanceof Element) || element.matches("input,textarea,select,[contenteditable='true']")) return;
    Array.from(element.childNodes)
      .filter((node) => node.nodeType === Node.TEXT_NODE)
      .forEach((node) => setMaskedTextNode(node, ""));
    let generated = Array.from(element.children).find((child) => child.hasAttribute(GENERATED_ATTRIBUTE) && child.classList.contains("wallos-privacy-replacement"));
    if (!generated) {
      generated = document.createElement("span");
      generated.className = "wallos-privacy-replacement";
      generated.setAttribute(GENERATED_ATTRIBUTE, "replacement");
      element.appendChild(generated);
      generatedNodes.add(generated);
    }
    if (generated.textContent !== String(replacement ?? "")) generated.textContent = String(replacement ?? "");
    addOwnedClass(element, REPLACED_CLASS);
    addOwnedClass(element, FIELD_CLASS);
  }

  function maskImage(image, key) {
    if (!(image instanceof HTMLImageElement)) return;
    const icon = fakeIcon(key);
    const name = fakeName(key, "image");
    setMaskedAttribute(image, "srcset", "");
    setMaskedAttribute(image, "sizes", null);
    setMaskedAttribute(image, "data-srcset", "");
    setMaskedAttribute(image, "data-src", icon);
    setMaskedAttribute(image, "src", icon);
    setMaskedAttribute(image, "alt", name);
    setMaskedAttribute(image, "title", name);
    const picture = image.closest("picture");
    if (picture) {
      picture.querySelectorAll("source").forEach((source) => {
        setMaskedAttribute(source, "srcset", icon);
        setMaskedAttribute(source, "data-srcset", icon);
      });
    }
    addOwnedClass(image, FIELD_CLASS);
  }

  function ensureIcon(container, key, extraClass) {
    if (!(container instanceof Element)) return;
    const images = container.querySelectorAll("img");
    if (images.length > 0) {
      images.forEach((image, index) => maskImage(image, `${key}:${index}`));
      return;
    }
    let image = Array.from(container.children).find((child) => child.matches(`img[${GENERATED_ATTRIBUTE}="icon"]`));
    if (!image) {
      image = document.createElement("img");
      image.setAttribute(GENERATED_ATTRIBUTE, "icon");
      image.className = `wallos-privacy-generated-icon${extraClass ? ` ${extraClass}` : ""}`;
      image.alt = "";
      container.appendChild(image);
      generatedNodes.add(image);
    }
    image.src = fakeIcon(key);
    addOwnedClass(container, ICON_HOST_CLASS);
  }

  function elementKey(element, scope, index) {
    const owner = element.closest("[data-subscription-id],[data-id]");
    const identity = owner
      ? (owner.getAttribute("data-subscription-id") || owner.getAttribute("data-id"))
      : "";
    return `${scope}:${identity || index}`;
  }

  function fakeDate(key) {
    const number = hashNumber(`date|${key}`);
    if (runtimeConfig.labels.date) return runtimeConfig.labels.date;
    return `2027-${String(1 + (number % 12)).padStart(2, "0")}-${String(1 + ((number >>> 7) % 28)).padStart(2, "0")}`;
  }

  function fakeStatistic(original, key) {
    const value = String(original || "").trim();
    const number = hashNumber(`stat|${key}`);
    if (value.includes("%")) return `${(18 + (number % 730) / 10).toFixed(1)}%`;
    if (/^[-+]?\d+$/.test(value.replace(/\s/g, ""))) return String(3 + (number % 24));
    if (/\d/.test(value)) return fakePrice(value, `stat:${key}`);
    return fakeDescription(key, "stat");
  }

  function markMasked(element) {
    addOwnedClass(element, MASKED_CLASS);
  }

  function sanitizeSubscriptionCards() {
    document.querySelectorAll("#subscriptions .subscription").forEach((card, index) => {
      const key = elementKey(card, "subscription", index);
      const name = fakeName(key, "subscription");
      setMaskedAttribute(card, "data-name", name);
      setMaskedAttribute(card, "aria-label", name);
      card.querySelectorAll(".subscription-main > .name, .subscription-secondary > .name").forEach((element) => maskDirectText(element, name));
      card.querySelectorAll(".subscription-main .price .value").forEach((element) => maskDirectText(element, fakePrice(key, "subscription-price")));
      card.querySelectorAll(".subscription-main .price .original_price").forEach((element) => maskDirectText(element, `(${fakePrice(key, "subscription-original-price")})`));
      card.querySelectorAll(".subscription-main .next-value").forEach((element) => maskDirectText(element, fakeDate(key)));
      card.querySelectorAll(".subscription-secondary > .payer_user").forEach((element) => maskDirectText(element, runtimeConfig.labels.payer));
      card.querySelectorAll(".subscription-secondary > .category").forEach((element) => maskDirectText(element, runtimeConfig.labels.category));
      card.querySelectorAll(".subscription-main .payment_method img, .subscription-media img, .subscription-detail-images img").forEach((image, imageIndex) => maskImage(image, `${key}:media:${imageIndex}`));
      card.querySelectorAll(".subscription-main > .logo").forEach((logo) => ensureIcon(logo, `${key}:logo`));
      card.querySelectorAll(".subscription-secondary .url a").forEach((anchor) => setMaskedAttribute(anchor, "href", "#"));
      card.querySelectorAll(".subscription-notes-content").forEach((notes) => maskRichContent(notes, fakeDescription(key, "notes")));
      card.querySelectorAll(".subscription-price-rules, .subscription-payment-records, .subscription-value-metrics, .subscription-value-details").forEach((details, detailIndex) => {
        maskRichContent(details, `${fakeDescription(`${key}:${detailIndex}`, "details")} ${fakePrice(`${key}:${detailIndex}`, "details")}`);
      });
      markMasked(card);
    });
  }

  function sanitizeDashboard() {
    document.querySelectorAll(".dashboard-subscriptions-list > .subscription-item").forEach((card, index) => {
      const key = elementKey(card, "dashboard", index);
      if (card.classList.contains("thin")) {
        card.querySelectorAll(".subscription-item-value").forEach((value) => maskDirectText(value, fakeStatistic(originalElementText(value), key)));
      } else {
        const name = fakeName(key, "dashboard");
        setMaskedAttribute(card, "aria-label", name);
        card.querySelectorAll(".subscription-item-title").forEach((title) => maskDirectText(title, name));
        card.querySelectorAll(".subscription-item-price").forEach((price) => maskDirectText(price, fakePrice(key, "dashboard-price")));
        card.querySelectorAll(".subscription-item-date").forEach((date) => maskDirectText(date, fakeDate(key)));
        card.querySelectorAll("img.subscription-item-logo").forEach((image) => maskImage(image, `${key}:logo`));
      }
      markMasked(card);
    });

    document.querySelectorAll(".ai-recommendation-item").forEach((item, index) => {
      const key = elementKey(item, "ai", index);
      const titlePool = runtimeConfig.labels.aiTitles;
      const title = titlePool[hashNumber(key) % titlePool.length];
      item.querySelectorAll(".ai-recommendation-header h3").forEach((heading) => maskRichContent(heading, `${index + 1}. ${title}`));
      item.querySelectorAll("p.collapsible").forEach((description) => maskRichContent(description, fakeDescription(key, "ai-description")));
      item.querySelectorAll(".ai-recommendation-savings").forEach((savings) => maskRichContent(savings, fakePrice(key, "ai-savings")));
      markMasked(item);
    });

    document.querySelectorAll(".budget-visualizer-figures strong, .budget-visualizer-figures span").forEach((value, index) => {
      maskDirectText(value, fakeStatistic(originalElementText(value), `budget-figure:${index}`));
    });
    document.querySelectorAll(".budget-visualizer-legend-item strong").forEach((value, index) => {
      maskDirectText(value, fakeStatistic(originalElementText(value), `budget-legend:${index}`));
    });
    document.querySelectorAll(".budget-visualizer-segment").forEach((segment, index) => {
      setMaskedAttribute(segment, "title", `${runtimeConfig.labels.chartSubtitle}: ${fakePrice(`budget-segment:${index}`, "budget-segment")}`);
      addOwnedClass(segment, FIELD_CLASS);
    });
    document.querySelectorAll(".budget-visualizer").forEach((visualizer) => markMasked(visualizer));
  }

  function sanitizeCalendar() {
    document.querySelectorAll(".calendar-subscription-title").forEach((title, index) => {
      const key = elementKey(title, "calendar", index);
      maskDirectText(title, fakeName(key, "calendar"));
      markMasked(title);
    });
    document.querySelectorAll(".calendar-monthly-stats .statistic").forEach((statistic, index) => {
      statistic.querySelectorAll(":scope > span").forEach((value) => maskDirectText(value, fakeStatistic(originalElementText(value), `calendar-stat:${index}`)));
      markMasked(statistic);
    });
    document.querySelectorAll(".over-budget").forEach((warning, index) => {
      maskRichContent(warning, `${fakeDescription(index, "budget-warning")} ${fakePrice(String(index), "budget-warning")}`);
      markMasked(warning);
    });

    const content = document.getElementById("subscriptionModalContent");
    if (content) {
      const key = "calendar-modal";
      content.querySelectorAll(".modal-header h3").forEach((title) => maskDirectText(title, fakeName(key, "calendar-modal")));
      content.querySelectorAll("img").forEach((image, index) => maskImage(image, `${key}:image:${index}`));
      content.querySelectorAll(".subscription-info > p").forEach((paragraph, index) => {
        maskRichContent(paragraph, index === 0 ? fakePrice(key, "calendar-modal-price") : fakeDescription(`${key}:${index}`, "calendar-modal"));
      });
      content.querySelectorAll(".calendar-subscription-notes .subscription-markdown").forEach((notes) => maskRichContent(notes, fakeDescription(key, "calendar-notes")));
      markMasked(content);
    }
  }

  function replaceAmountsInText(value, key) {
    let occurrence = 0;
    return String(value).replace(/[-+]?(?:(?:US|HK|CA|AU|NZ|S|NT)\$|[$€£¥₹₽₩₺₫₴₦₱])?\s*\d[\d\s.,]*(?:%)?/gu, (match) => {
      occurrence += 1;
      if (match.includes("%")) return fakeStatistic(match, `${key}:${occurrence}`);
      return fakePrice(match, `${key}:${occurrence}`);
    });
  }

  function ensureChartPlaceholder(graph, key) {
    let placeholder = Array.from(graph.children).find((child) => child.hasAttribute(GENERATED_ATTRIBUTE) && child.classList.contains("wallos-privacy-chart-placeholder"));
    if (placeholder) return;
    placeholder = document.createElement("div");
    placeholder.className = "wallos-privacy-chart-placeholder";
    placeholder.setAttribute(GENERATED_ATTRIBUTE, "chart");
    placeholder.setAttribute("aria-hidden", "true");
    for (let index = 0; index < 8; index += 1) {
      const bar = document.createElement("span");
      bar.className = "wallos-privacy-chart-bar";
      bar.style.setProperty("--wallos-privacy-bar-height", `${22 + (hashNumber(`${key}:${index}`) % 70)}%`);
      placeholder.appendChild(bar);
    }
    graph.appendChild(placeholder);
    generatedNodes.add(placeholder);
  }

  function sanitizeStatistics() {
    document.querySelectorAll(".statistics > .statistic").forEach((statistic, index) => {
      if (statistic.closest(".calendar-monthly-stats")) return;
      const value = statistic.querySelector(":scope > span");
      if (value) maskDirectText(value, fakeStatistic(originalElementText(value), `statistic:${index}`));
      if (statistic.classList.contains("short")) {
        statistic.querySelectorAll(".subtitle").forEach((subtitle) => {
          subtitle.querySelectorAll("img").forEach((image) => maskImage(image, `most-expensive:${index}`));
          if (!subtitle.querySelector("img")) maskDirectText(subtitle, fakeName(`most-expensive:${index}`, "stats"));
        });
      }
      markMasked(statistic);
    });

    document.querySelectorAll(".graphs > .graph, #stats-graphs .graph").forEach((graph, index) => {
      const key = `graph:${index}`;
      graph.querySelectorAll(":scope > header").forEach((header) => {
        maskTransformedDirectText(header, (text) => replaceAmountsInText(text, `${key}:header`));
      });
      graph.querySelectorAll(".sub-header").forEach((subtitle) => {
        maskTransformedDirectText(subtitle, (text) => replaceAmountsInText(text, `${key}:subtitle`));
      });
      graph.querySelectorAll("canvas").forEach((canvas) => {
        setMaskedAttribute(canvas, "aria-label", runtimeConfig.labels.chartSubtitle);
        setMaskedAttribute(canvas, "title", runtimeConfig.labels.chartSubtitle);
        addOwnedClass(canvas, FIELD_CLASS);
      });
      ensureChartPlaceholder(graph, key);
      markMasked(graph);
    });

    document.querySelectorAll(".filter-item").forEach((filter, index) => {
      maskDirectText(filter, fakeName(`filter:${index}`, "stats-filter"));
    });
  }

  function sanitizeMetricModal() {
    const modal = document.getElementById("metric-explanation-modal");
    if (!modal) return;
    const formula = document.getElementById("metric-explanation-formula");
    const summary = document.getElementById("metric-explanation-summary");
    const items = document.getElementById("metric-explanation-items");
    if (formula) maskRichContent(formula, runtimeConfig.labels.metricFormula);
    if (summary) maskRichContent(summary, runtimeConfig.labels.metricSummary);
    if (items) maskRichContent(items, runtimeConfig.labels.metricItems);
    markMasked(modal);
  }

  function sanitizeRecycleBin() {
    document.querySelectorAll(".subscription-trash-card").forEach((card, index) => {
      const key = elementKey(card, "trash", index);
      card.querySelectorAll(".subscription-trash-card-header h3").forEach((heading) => maskDirectText(heading, fakeName(key, "trash")));
      card.querySelectorAll(".subscription-trash-card-header img").forEach((image) => maskImage(image, `${key}:logo`));
      card.querySelectorAll(".subscription-trash-card-header p").forEach((paragraph, paragraphIndex) => maskDirectText(paragraph, fakeDate(`${key}:${paragraphIndex}`)));
      card.querySelectorAll(".subscription-trash-card-meta p").forEach((paragraph, paragraphIndex) => {
        const replacement = paragraphIndex === 0
          ? fakePrice(key, "trash-price")
          : (paragraphIndex === 1 ? fakeDate(key) : fakeDescription(`${key}:${paragraphIndex}`, "trash"));
        maskDirectText(paragraph, replacement);
      });
      markMasked(card);
    });
    document.querySelectorAll(".subscription-recycle-bin-modal .section-count-badge").forEach((count) => maskDirectText(count, String(2 + (hashNumber("trash-count") % 7))));
  }

  function sanitizeDetailsModal() {
    const modal = document.getElementById("subscription-details");
    if (!modal) return;
    const key = "subscription-details";
    const name = document.getElementById("details-name");
    const price = document.getElementById("details-price");
    const logo = document.getElementById("details-logo");
    if (name) maskDirectText(name, fakeName(key, "details"));
    if (price) maskDirectText(price, fakePrice(key, "details-price"));
    if (logo) ensureIcon(logo, `${key}:logo`, "screenshot-privacy-demo-icon");
    const replacements = {
      "details-next-payment": fakeDate(`${key}:next`),
      "details-start-date": fakeDate(`${key}:start`),
      "details-category": runtimeConfig.labels.category,
      "details-payer": runtimeConfig.labels.payer,
      "details-payment-name": runtimeConfig.labels.paymentMethod,
      "details-notifications": fakeDescription(key, "notification"),
      "details-cancellation": fakeDate(`${key}:cancel`),
      "details-replacement": fakeName(`${key}:replacement`, "details"),
      "details-notes": fakeDescription(key, "details-notes"),
    };
    Object.keys(replacements).forEach((id) => {
      const element = document.getElementById(id);
      if (element) maskDirectText(element, replacements[id]);
    });
    const paymentIcon = document.getElementById("details-payment-icon");
    if (paymentIcon) maskImage(paymentIcon, `${key}:payment`);
    const urlButton = document.getElementById("details-url-button");
    if (urlButton) setMaskedAttribute(urlButton, "href", "#");
    markMasked(modal);
  }

  function markBlockedActions() {
    document.querySelectorAll(BLOCKED_SELECTOR).forEach((trigger) => {
      const message = runtimeConfig.blockedMessage || runtimeConfig.labels.blockedMessage;
      setMaskedAttribute(trigger, "aria-disabled", "true");
      setMaskedAttribute(trigger, "title", message);
      addOwnedClass(trigger, "wallos-privacy-action-blocked");
    });
  }

  function scanDocument() {
    if (!enabled) return;
    sanitizeSubscriptionCards();
    sanitizeDashboard();
    sanitizeCalendar();
    sanitizeStatistics();
    sanitizeMetricModal();
    sanitizeRecycleBin();
    sanitizeDetailsModal();
    markBlockedActions();
  }

  function queueScan() {
    if (!enabled || scanQueued) return;
    scanQueued = true;
    queueMicrotask(() => {
      scanQueued = false;
      scanDocument();
    });
  }

  function startObserver() {
    if (observer || !document.documentElement) return;
    observer = new MutationObserver(queueScan);
    observer.observe(document.documentElement, {
      subtree: true,
      childList: true,
      characterData: true,
      attributes: true,
      attributeFilter: ["class", "src", "srcset", "data-src", "data-srcset", "href", "title", "alt", "aria-label"],
    });
  }

  function stopObserver() {
    if (!observer) return;
    observer.disconnect();
    observer = null;
    scanQueued = false;
  }

  function restoreDocument() {
    stopObserver();
    if (blockedNoticeTimer) global.clearTimeout(blockedNoticeTimer);
    blockedNoticeTimer = 0;
    blockedNotice = null;

    generatedNodes.forEach((node) => {
      if (node && node.parentNode) node.remove();
    });
    generatedNodes.clear();

    attributeRecords.forEach((records, element) => {
      records.forEach((record, name) => {
        if (record.had) element.setAttribute(name, record.original === null ? "" : record.original);
        else element.removeAttribute(name);
      });
    });
    attributeRecords.clear();

    textRecords.forEach((record, node) => {
      if (node) node.nodeValue = record.original;
    });
    textRecords.clear();

    ownedClasses.forEach((classes, element) => {
      classes.forEach((className) => element.classList.remove(className));
    });
    ownedClasses.clear();
    document.documentElement.classList.remove(ROOT_CLASS);
  }

  function dispatchChange() {
    try {
      global.dispatchEvent(new CustomEvent("wallos:screenshot-privacy-change", { detail: { enabled } }));
    } catch (error) {
      // The visual state is already applied; custom events are only advisory.
    }
  }

  function setEnabled(nextEnabled) {
    refreshRuntimeConfig();
    enabled = normalizeBoolean(nextEnabled);
    if (!global.WallosScreenshotPrivacyConfig || typeof global.WallosScreenshotPrivacyConfig !== "object") {
      global.WallosScreenshotPrivacyConfig = {};
    }
    global.WallosScreenshotPrivacyConfig.enabled = enabled;

    if (enabled) {
      document.documentElement.classList.add(ROOT_CLASS);
      startObserver();
      scanDocument();
    } else {
      restoreDocument();
    }
    announceServerPrivacyChange(enabled);
    dispatchChange();
    return enabled;
  }

  function showBlockedMessage(action) {
    const message = runtimeConfig.blockedMessage || runtimeConfig.labels.blockedMessage;
    try {
      global.dispatchEvent(new CustomEvent("wallos:screenshot-privacy-blocked", {
        detail: { action: String(action || ""), message },
      }));
    } catch (error) {
      // Continue to the visible fallback below.
    }

    if (typeof global.showErrorMessage === "function") {
      global.showErrorMessage(message);
      return;
    }

    if (blockedNotice && blockedNotice.parentNode) blockedNotice.remove();
    if (blockedNoticeTimer) global.clearTimeout(blockedNoticeTimer);
    blockedNotice = document.createElement("div");
    blockedNotice.className = "wallos-privacy-blocked-notice";
    blockedNotice.setAttribute("role", "status");
    blockedNotice.setAttribute("aria-live", "polite");
    blockedNotice.setAttribute(GENERATED_ATTRIBUTE, "notice");
    blockedNotice.textContent = message;
    document.body.appendChild(blockedNotice);
    generatedNodes.add(blockedNotice);
    blockedNoticeTimer = global.setTimeout(() => {
      if (blockedNotice && blockedNotice.parentNode) blockedNotice.remove();
      generatedNodes.delete(blockedNotice);
      blockedNotice = null;
      blockedNoticeTimer = 0;
    }, 3200);
  }

  function blockSensitiveAction(event) {
    if (!enabled || !(event.target instanceof Element)) return;
    const trigger = event.target.closest(BLOCKED_SELECTOR);
    if (!trigger) return;
    event.preventDefault();
    event.stopPropagation();
    event.stopImmediatePropagation();
    showBlockedMessage(trigger.getAttribute("data-subscription-action") || trigger.id || "sensitive-action");
  }

  function isEnabled() {
    return enabled;
  }

  function destroy() {
    enabled = false;
    restoreDocument();
    document.removeEventListener("click", blockSensitiveAction, true);
    document.removeEventListener("auxclick", blockSensitiveAction, true);
    global.removeEventListener("storage", handleStorageSync);
    global.removeEventListener("pageshow", handlePageShowSync);
    document.removeEventListener("visibilitychange", handleVisibilitySync);
    if (syncChannel) {
      syncChannel.close();
      syncChannel = null;
    }
  }

  const api = {
    isEnabled,
    setEnabled,
    sanitizeSubscription,
    sanitizeChartData,
    fakeName,
    fakePrice,
    fakeIcon,
    destroy,
  };

  global.WallosScreenshotPrivacy = api;
  startCrossTabSync();
  document.addEventListener("click", blockSensitiveAction, true);
  document.addEventListener("auxclick", blockSensitiveAction, true);

  if (enabled) {
    document.documentElement.classList.add(ROOT_CLASS);
    startObserver();
    scanDocument();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => {
      if (enabled) {
        startObserver();
        scanDocument();
      }
    }, { once: true });
  } else if (enabled) {
    scanDocument();
  }
})(window);
