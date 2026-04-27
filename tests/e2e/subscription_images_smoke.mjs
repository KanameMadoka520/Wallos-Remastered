#!/usr/bin/env node

import fs from "node:fs/promises";
import path from "node:path";
import zlib from "node:zlib";

let chromium;
try {
  ({ chromium } = await import("playwright"));
} catch (error) {
  console.error("SKIP: Playwright is not installed. Run `npm install` before this E2E check.");
  process.exit(77);
}

const baseUrl = (process.env.WALLOS_BASE_URL || "http://127.0.0.1:18282").replace(/\/$/, "");
const username = process.env.WALLOS_TEST_USERNAME || "";
const password = process.env.WALLOS_TEST_PASSWORD || "";
const headless = process.env.WALLOS_E2E_HEADLESS !== "0";
const artifactRoot = path.resolve(process.env.WALLOS_E2E_ARTIFACT_DIR || "screenshots/e2e");

if (!username || !password) {
  console.error("FAIL: WALLOS_TEST_USERNAME and WALLOS_TEST_PASSWORD are required.");
  process.exit(1);
}

function buildCrcTable() {
  const table = new Uint32Array(256);
  for (let index = 0; index < 256; index += 1) {
    let value = index;
    for (let bit = 0; bit < 8; bit += 1) {
      value = (value & 1) ? (0xEDB88320 ^ (value >>> 1)) : (value >>> 1);
    }
    table[index] = value >>> 0;
  }
  return table;
}

const crcTable = buildCrcTable();

function crc32(buffer) {
  let crc = 0xFFFFFFFF;
  for (const byte of buffer) {
    crc = crcTable[(crc ^ byte) & 0xFF] ^ (crc >>> 8);
  }
  return (crc ^ 0xFFFFFFFF) >>> 0;
}

function pngChunk(type, data = Buffer.alloc(0)) {
  const typeBuffer = Buffer.from(type, "ascii");
  const length = Buffer.alloc(4);
  length.writeUInt32BE(data.length, 0);
  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(Buffer.concat([typeBuffer, data])), 0);
  return Buffer.concat([length, typeBuffer, data, crc]);
}

function createPngBuffer(width, height) {
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(width, 0);
  ihdr.writeUInt32BE(height, 4);
  ihdr[8] = 8;
  ihdr[9] = 6;
  ihdr[10] = 0;
  ihdr[11] = 0;
  ihdr[12] = 0;

  const rows = [];
  for (let y = 0; y < height; y += 1) {
    const row = Buffer.alloc(1 + width * 4);
    row[0] = 0;
    for (let x = 0; x < width; x += 1) {
      const offset = 1 + x * 4;
      row[offset] = 109;
      row[offset + 1] = 74;
      row[offset + 2] = 255;
      row[offset + 3] = 255;
    }
    rows.push(row);
  }

  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A]),
    pngChunk("IHDR", ihdr),
    pngChunk("IDAT", zlib.deflateSync(Buffer.concat(rows), { level: 9 })),
    pngChunk("IEND"),
  ]);
}

const originalImageBuffer = createPngBuffer(8, 8);
const uploadedImageName = "wallos-e2e-passthrough.png";

const diagnostics = {
  consoleErrors: [],
  pageErrors: [],
  failedRequests: [],
  failedResponses: [],
};

const browser = await chromium.launch({ headless });
const context = await browser.newContext({
  viewport: { width: 1440, height: 1000 },
  ignoreHTTPSErrors: true,
});
const page = await context.newPage();

let createdSubscriptionId = "";
let createdSubscriptionName = "";
let uploadedImageId = "";
let uploadedImageUrls = null;

function formatUrl(targetUrl) {
  return String(targetUrl || "").replace(baseUrl, "");
}

function shouldIgnoreConsoleError(message) {
  const normalizedMessage = String(message || "").toLowerCase();
  return normalizedMessage.includes("favicon.ico")
    || normalizedMessage.includes("net::err_aborted")
    || normalizedMessage.includes("failed to load resource: the server responded with a status of 404");
}

function attachDiagnostics(targetPage) {
  targetPage.on("console", (message) => {
    if (message.type() !== "error") {
      return;
    }

    const text = message.text();
    if (!shouldIgnoreConsoleError(text)) {
      diagnostics.consoleErrors.push(text);
    }
  });

  targetPage.on("pageerror", (error) => {
    diagnostics.pageErrors.push(error?.stack || error?.message || String(error));
  });

  targetPage.on("requestfailed", (request) => {
    const failureText = request.failure()?.errorText || "";
    if (failureText.includes("net::ERR_ABORTED")) {
      return;
    }

    diagnostics.failedRequests.push(`${request.method()} ${formatUrl(request.url())} :: ${failureText}`);
  });

  targetPage.on("response", (response) => {
    const status = response.status();
    const responseUrl = response.url();
    const isEndpoint = responseUrl.includes("/endpoints/");
    if (status >= 500 || (isEndpoint && status >= 400 && !responseUrl.includes("/endpoints/media/subscriptionimage.php"))) {
      diagnostics.failedResponses.push(`HTTP ${status} ${formatUrl(responseUrl)}`);
    }
  });

  targetPage.on("dialog", async (dialog) => {
    await dialog.dismiss().catch(() => {});
  });
}

async function writeFailureArtifacts(error) {
  await fs.mkdir(artifactRoot, { recursive: true });
  const stamp = new Date().toISOString().replace(/[:.]/g, "-");
  const screenshotPath = path.join(artifactRoot, `subscription-images-smoke-${stamp}.png`);
  const htmlPath = path.join(artifactRoot, `subscription-images-smoke-${stamp}.html`);
  const diagnosticsPath = path.join(artifactRoot, `subscription-images-smoke-${stamp}.json`);

  await page.screenshot({ path: screenshotPath, fullPage: true }).catch(() => null);
  await fs.writeFile(htmlPath, await page.content().catch(() => "")).catch(() => null);
  await fs.writeFile(diagnosticsPath, JSON.stringify({
    error: error?.stack || error?.message || String(error),
    url: page.url(),
    createdSubscriptionId,
    uploadedImageId,
    uploadedImageUrls,
    diagnostics,
  }, null, 2)).catch(() => null);

  console.error(`Artifacts: ${screenshotPath}`);
  console.error(`Artifacts: ${htmlPath}`);
  console.error(`Artifacts: ${diagnosticsPath}`);
}

async function step(label, callback) {
  const startedAt = Date.now();
  process.stdout.write(`STEP ${label} ... `);
  try {
    await callback();
    console.log(`${Date.now() - startedAt}ms`);
  } catch (error) {
    error.message = `${label}: ${error.message || error}`;
    console.log("FAIL");
    throw error;
  }
}

async function expectVisible(selector, label, timeout = 15000) {
  const locator = page.locator(selector).first();
  await locator.waitFor({ state: "visible", timeout });
  return locator;
}

async function readBinaryFromAuthenticatedPage(relativeUrl) {
  return page.evaluate(async (url) => {
    const response = await fetch(url, { credentials: "same-origin" });
    const body = Array.from(new Uint8Array(await response.arrayBuffer()));
    return {
      status: response.status,
      contentType: response.headers.get("Content-Type") || "",
      contentLength: response.headers.get("Content-Length") || "",
      body,
    };
  }, relativeUrl);
}

async function cleanupCreatedSubscription() {
  if (!createdSubscriptionId) {
    return;
  }

  await page.evaluate(async (subscriptionId) => {
    const headers = { "Content-Type": "application/json" };
    if (window.csrfToken) {
      headers["X-CSRF-Token"] = window.csrfToken;
    }

    await fetch("endpoints/subscription/delete.php", {
      method: "POST",
      headers,
      credentials: "same-origin",
      body: JSON.stringify({ id: Number(subscriptionId) }),
    }).catch(() => null);

    await fetch("endpoints/subscription/permanentdelete.php", {
      method: "POST",
      headers,
      credentials: "same-origin",
      body: JSON.stringify({ id: Number(subscriptionId) }),
    }).catch(() => null);
  }, createdSubscriptionId).catch(() => null);
}

function assertNoBrowserRuntimeErrors() {
  const problems = [
    ...diagnostics.pageErrors.map((entry) => `pageerror: ${entry}`),
    ...diagnostics.consoleErrors.map((entry) => `console.error: ${entry}`),
    ...diagnostics.failedRequests.map((entry) => `requestfailed: ${entry}`),
    ...diagnostics.failedResponses.map((entry) => `response: ${entry}`),
  ];

  if (problems.length > 0) {
    throw new Error(`Browser diagnostics found ${problems.length} problem(s):\n${problems.slice(0, 8).join("\n")}`);
  }
}

attachDiagnostics(page);

try {
  await step("login with test account", async () => {
    await page.goto(`${baseUrl}/login.php`, { waitUntil: "domcontentloaded" });
    await page.locator("#username").fill(username);
    await page.locator("#password").fill(password);
    await Promise.all([
      page.waitForURL((url) => !url.pathname.endsWith("/login.php"), { timeout: 15000 }).catch(() => null),
      page.locator('input[type="submit"]').click(),
    ]);

    if (page.url().includes("/login.php")) {
      throw new Error("login did not leave login.php");
    }
  });

  await step("open add-subscription modal with upload permission", async () => {
    await page.goto(`${baseUrl}/subscriptions.php`, { waitUntil: "domcontentloaded" });
    await expectVisible("#subscriptions", "subscriptions container");
    await page.locator("#subscription-page-loading-overlay.is-visible").waitFor({ state: "hidden", timeout: 15000 }).catch(() => null);
    await page.locator('[data-subscription-action="open-add-subscription"]').first().click();
    await expectVisible("#subscription-form.is-open", "add subscription modal");

    const canUpload = await page.locator("#subs-form").getAttribute("data-can-upload-detail-image");
    if (canUpload !== "1") {
      throw new Error("test account cannot upload subscription detail images");
    }
  });

  await step("upload image and save with original passthrough enabled", async () => {
    createdSubscriptionName = `E2E Image ${Date.now()}`;
    await page.locator("#name").fill(createdSubscriptionName);
    await page.locator("#price").fill("2.34");
    await page.locator("#start_date").fill(new Date().toISOString().slice(0, 10));
    const nextDate = new Date(Date.now() + 31 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10);
    await page.locator("#next_payment").fill(nextDate);

    const compressCheckbox = page.locator("#compress_subscription_image");
    if (await compressCheckbox.count()) {
      await compressCheckbox.setChecked(false, { force: true });
    }

    await page.locator("#detail-image-upload").setInputFiles({
      name: uploadedImageName,
      mimeType: "image/png",
      buffer: originalImageBuffer,
    });
    await expectVisible(".subscription-detail-image-card.new", "new selected image card");
    const selectedSummary = await page.locator(".subscription-detail-image-size-summary").first().textContent();
    if (String(selectedSummary || "").includes("[Translation Missing]")) {
      throw new Error(`selected image size summary has missing translation: ${selectedSummary}`);
    }
    if (!String(selectedSummary || "").includes("B")) {
      throw new Error(`selected image size summary did not render bytes: ${selectedSummary}`);
    }

    await page.locator("#save-button").click();
    await page.locator("#subscription-form.is-open").waitFor({ state: "detached", timeout: 20000 }).catch(async () => {
      if (await page.locator("#subscription-form.is-open").count()) {
        throw new Error("add subscription modal did not close after image upload save");
      }
    });

    const createdCard = page.locator(".subscription-container", { hasText: createdSubscriptionName }).first();
    await createdCard.waitFor({ state: "visible", timeout: 20000 });
    createdSubscriptionId = await createdCard.getAttribute("data-id") || "";
    if (!createdSubscriptionId) {
      throw new Error("created image subscription card did not expose data-id");
    }
  });

  await step("uploaded image appears in card and opens viewer with size metadata", async () => {
    const card = page.locator(".subscription-container", { hasText: createdSubscriptionName }).first();
    const subscription = card.locator(".subscription").first();
    if (!await subscription.evaluate((node) => node.classList.contains("is-open")).catch(() => false)) {
      await subscription.click();
    }

    const mediaItem = card.locator('.subscription-media-item[data-uploaded-image-id]').first();
    await mediaItem.waitFor({ state: "visible", timeout: 15000 });
    uploadedImageId = await mediaItem.getAttribute("data-uploaded-image-id") || "";
    if (!uploadedImageId) {
      throw new Error("uploaded image did not expose data-uploaded-image-id");
    }

    uploadedImageUrls = {
      thumbnail: await mediaItem.getAttribute("data-viewer-src"),
      preview: await mediaItem.getAttribute("data-viewer-src"),
      original: await mediaItem.getAttribute("data-viewer-original"),
      download: await mediaItem.getAttribute("data-viewer-download"),
      thumbnailLabel: await mediaItem.getAttribute("data-viewer-size-thumbnail"),
      previewLabel: await mediaItem.getAttribute("data-viewer-size-preview"),
      originalLabel: await mediaItem.getAttribute("data-viewer-size-original"),
      previewReusedOriginal: await mediaItem.getAttribute("data-viewer-preview-reused-original"),
      thumbnailReusedOriginal: await mediaItem.getAttribute("data-viewer-thumbnail-reused-original"),
    };

    if (!uploadedImageUrls.original || !uploadedImageUrls.preview || !uploadedImageUrls.thumbnail) {
      throw new Error(`uploaded image URLs are incomplete: ${JSON.stringify(uploadedImageUrls)}`);
    }

    await mediaItem.click();
    await expectVisible("#subscription-image-viewer.is-open", "image viewer");
    await expectVisible("#subscription-image-viewer-size-thumbnail", "thumbnail size label");
    await expectVisible("#subscription-image-viewer-size-preview", "preview size label");
    await expectVisible("#subscription-image-viewer-size-original", "original size label");

    const originalSizeLabel = await page.locator("#subscription-image-viewer-size-original").textContent();
    if (!String(originalSizeLabel || "").trim() || String(originalSizeLabel || "").includes("未知")) {
      throw new Error(`original size label was not populated: ${originalSizeLabel}`);
    }

    await page.locator('#subscription-image-viewer [data-subscription-action="close-image-viewer"]').first().click();
    await page.locator("#subscription-image-viewer.is-open").waitFor({ state: "hidden", timeout: 10000 }).catch(() => null);
  });

  await step("media endpoints preserve original bytes and avoid oversized derivatives", async () => {
    const originalResponse = await readBinaryFromAuthenticatedPage(uploadedImageUrls.original);
    const previewResponse = await readBinaryFromAuthenticatedPage(uploadedImageUrls.preview);
    const thumbnailResponse = await readBinaryFromAuthenticatedPage(`endpoints/media/subscriptionimage.php?id=${uploadedImageId}&variant=thumbnail`);

    for (const [label, response] of Object.entries({
      original: originalResponse,
      preview: previewResponse,
      thumbnail: thumbnailResponse,
    })) {
      if (response.status !== 200) {
        throw new Error(`${label} endpoint returned HTTP ${response.status}`);
      }
      if (!String(response.contentType || "").toLowerCase().startsWith("image/")) {
        throw new Error(`${label} endpoint returned non-image content-type ${response.contentType}`);
      }
      if (!Array.isArray(response.body) || response.body.length === 0) {
        throw new Error(`${label} endpoint returned empty image body`);
      }
    }

    const originalBytes = Buffer.from(originalResponse.body);
    if (!originalBytes.equals(originalImageBuffer)) {
      throw new Error(`original image was not byte-for-byte passthrough: expected ${originalImageBuffer.length}, got ${originalBytes.length}`);
    }

    if (previewResponse.body.length > originalBytes.length) {
      throw new Error(`preview variant is larger than original: ${previewResponse.body.length} > ${originalBytes.length}`);
    }
    if (thumbnailResponse.body.length > originalBytes.length) {
      throw new Error(`thumbnail variant is larger than original: ${thumbnailResponse.body.length} > ${originalBytes.length}`);
    }
  });

  await step("anonymous users cannot access uploaded subscription media", async () => {
    const anonymousContext = await browser.newContext({ ignoreHTTPSErrors: true });
    try {
      const response = await anonymousContext.request.get(`${baseUrl}/${uploadedImageUrls.original}`);
      if (![401, 403].includes(response.status())) {
        throw new Error(`anonymous media request should be denied, got HTTP ${response.status()}`);
      }
    } finally {
      await anonymousContext.close();
    }
  });

  await step("permanent deletion removes image record access", async () => {
    const originalUrl = uploadedImageUrls.original;
    await cleanupCreatedSubscription();
    createdSubscriptionId = "";

    const response = await readBinaryFromAuthenticatedPage(originalUrl);
    if (![403, 404].includes(response.status)) {
      throw new Error(`deleted media should no longer be readable, got HTTP ${response.status}`);
    }
  });

  assertNoBrowserRuntimeErrors();
  console.log("PASS: subscription image browser E2E smoke completed.");
  await browser.close();
  process.exit(0);
} catch (error) {
  await cleanupCreatedSubscription();
  await writeFailureArtifacts(error);
  await browser.close();
  console.error(`FAIL: ${error.message || error}`);
  process.exit(1);
}
