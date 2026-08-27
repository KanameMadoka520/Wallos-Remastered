#!/usr/bin/env node

import fs from "node:fs/promises";
import path from "node:path";

let chromium;
try {
  ({ chromium } = await import("playwright"));
} catch (error) {
  console.error("SKIP: Playwright is not installed. Run `npm install` before this E2E check.");
  process.exit(77);
}

const baseUrl = (process.env.WALLOS_BASE_URL || "http://127.0.0.1:18282").replace(/\/$/, "");
const headless = process.env.WALLOS_E2E_HEADLESS !== "0";
const artifactRoot = path.resolve(process.env.WALLOS_E2E_ARTIFACT_DIR || "screenshots/e2e");
const missingMarkerPattern = /\[(?:i18n String Missing|Translation Missing)\]/i;

const diagnostics = {
  consoleErrors: [],
  pageErrors: [],
  failedRequests: [],
};

const browser = await chromium.launch({ headless });
const context = await browser.newContext({
  viewport: { width: 1366, height: 900 },
  ignoreHTTPSErrors: true,
  serviceWorkers: "block",
});
const page = await context.newPage();

page.on("console", (message) => {
  if (message.type() === "error") {
    diagnostics.consoleErrors.push(message.text());
  }
});

page.on("pageerror", (error) => {
  diagnostics.pageErrors.push(error?.stack || error?.message || String(error));
});

page.on("requestfailed", (request) => {
  const failure = request.failure()?.errorText || "";
  if (!failure.includes("net::ERR_ABORTED")) {
    diagnostics.failedRequests.push(`${request.method()} ${request.url()} :: ${failure}`);
  }
});

async function writeFailureArtifacts(error) {
  await fs.mkdir(artifactRoot, { recursive: true });
  const stamp = new Date().toISOString().replace(/[:.]/g, "-");
  const prefix = path.join(artifactRoot, `i18n-smoke-${stamp}`);
  await page.screenshot({ path: `${prefix}.png`, fullPage: true }).catch(() => null);
  await fs.writeFile(`${prefix}.html`, await page.content().catch(() => "")).catch(() => null);
  await fs.writeFile(`${prefix}.json`, JSON.stringify({
    error: error?.stack || error?.message || String(error),
    url: page.url(),
    diagnostics,
  }, null, 2)).catch(() => null);
  console.error(`Artifacts: ${prefix}.png`);
  console.error(`Artifacts: ${prefix}.html`);
  console.error(`Artifacts: ${prefix}.json`);
}

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

try {
  const initialResponse = await page.goto(`${baseUrl}/login.php`, { waitUntil: "networkidle" });
  assert(initialResponse && initialResponse.ok(), `login.php returned HTTP ${initialResponse?.status() ?? "unknown"}`);

  const languageOptions = await page.locator("#public-page-language-login option").evaluateAll((options) => (
    options.map((option) => ({
      code: String(option.value || "").trim(),
      label: String(option.textContent || "").trim(),
    })).filter((option) => option.code)
  ));

  assert(languageOptions.length > 0, "the login language selector did not expose any languages");
  assert(languageOptions.some(({ code }) => code === "ar"), "Arabic is missing from the language selector");
  assert(languageOptions.some(({ code }) => code === "hu"), "Hungarian is missing from the language selector");
  assert(languageOptions.some(({ code }) => code === "jp"), "the legacy-compatible Japanese code `jp` is missing");
  assert(!languageOptions.some(({ code }) => code === "ja"), "Japanese appears twice instead of preserving the single `jp` selector entry");

  for (const { code, label } of languageOptions) {
    const response = await page.goto(
      `${baseUrl}/login.php?set_language=${encodeURIComponent(code)}`,
      { waitUntil: "networkidle" },
    );
    assert(response && response.ok(), `${code} (${label}) returned HTTP ${response?.status() ?? "unknown"}`);

    const html = await page.content();
    assert(!missingMarkerPattern.test(html), `${code} rendered an i18n missing marker in login.php`);

    const pageContract = await page.evaluate(() => {
      const english = globalThis.wallosI18nEnglish;
      const localized = globalThis.i18n;
      if (!english || typeof english !== "object") {
        return { error: "wallosI18nEnglish is unavailable" };
      }
      if (!localized || typeof localized !== "object") {
        return { error: "i18n is unavailable" };
      }
      if (typeof globalThis.translate !== "function") {
        return { error: "translate() is unavailable" };
      }

      const missing = [];
      const empty = [];
      for (const key of Object.keys(english)) {
        const value = globalThis.translate(key);
        if (value === "[Translation Missing]" || value === "[i18n String Missing]") {
          missing.push(key);
        } else if (typeof value !== "string" || value.trim() === "") {
          empty.push(key);
        }
      }

      return {
        baseKeyCount: Object.keys(english).length,
        effectiveKeyCount: Object.keys(localized).length,
        missing,
        empty,
        dir: document.documentElement.getAttribute("dir"),
        selectedLanguage: document.querySelector("#public-page-language-login")?.value || "",
      };
    });

    assert(!pageContract.error, `${code}: ${pageContract.error || "unknown browser i18n error"}`);
    assert(pageContract.baseKeyCount > 0, `${code}: the English JS baseline is empty`);
    assert(
      pageContract.effectiveKeyCount === pageContract.baseKeyCount,
      `${code}: effective JS dictionary has ${pageContract.effectiveKeyCount} keys; expected ${pageContract.baseKeyCount}`,
    );
    assert(pageContract.missing.length === 0, `${code}: missing JS keys: ${pageContract.missing.join(", ")}`);
    assert(pageContract.empty.length === 0, `${code}: empty JS translations: ${pageContract.empty.join(", ")}`);
    assert(pageContract.selectedLanguage === code, `${code}: selector reports ${pageContract.selectedLanguage || "none"}`);
    assert(pageContract.dir === (code === "ar" ? "rtl" : "ltr"), `${code}: unexpected document dir=${pageContract.dir}`);
  }

  const runtimeProblems = [
    ...diagnostics.pageErrors.map((entry) => `pageerror: ${entry}`),
    ...diagnostics.consoleErrors.map((entry) => `console.error: ${entry}`),
    ...diagnostics.failedRequests.map((entry) => `requestfailed: ${entry}`),
  ];
  assert(runtimeProblems.length === 0, `browser diagnostics found problems:\n${runtimeProblems.slice(0, 10).join("\n")}`);

  console.log(`PASS: ${languageOptions.length} login languages satisfy the PHP/JS i18n browser contract.`);
} catch (error) {
  await writeFailureArtifacts(error);
  console.error(`FAIL: ${error?.stack || error?.message || error}`);
  process.exitCode = 1;
} finally {
  await browser.close();
}
