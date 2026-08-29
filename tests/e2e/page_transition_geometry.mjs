#!/usr/bin/env node

import assert from "node:assert/strict";
import fs from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

let chromium;
try {
  ({ chromium } = await import("playwright"));
} catch (error) {
  console.error("SKIP: Playwright is not installed. Run `npm install` before this check.");
  process.exit(77);
}

const testDirectory = path.dirname(fileURLToPath(import.meta.url));
const repositoryRoot = path.resolve(testDirectory, "../..");
const transitionCssPath = path.join(repositoryRoot, "styles/page-transitions.css");
const transitionCss = await fs.readFile(transitionCssPath, "utf8");
const cssWithoutComments = transitionCss.replace(/\/\*[\s\S]*?\*\//g, "");

assert.doesNotMatch(
  cssWithoutComments,
  /\]\s+\.wallos-page-transition-loading\b/,
  "State classes must be attached to html (…].wallos-page-transition-loading), not selected as descendants",
);
assert.doesNotMatch(
  cssWithoutComments,
  /\]\s+\.wallos-page-transition-leaving\b/,
  "State classes must be attached to html (…].wallos-page-transition-leaving), not selected as descendants",
);

const viewports = [
  { name: "desktop", width: 1440, height: 1000 },
  { name: "phone", width: 375, height: 812 },
  { name: "landscape", width: 812, height: 375 },
];
const styles = ["shutter", "bluearchive", "bluearchive_theme"];
const states = ["loading", "leaving"];
const centerTolerancePx = 2;
const opacityTolerance = 0.01;

const overlayMarkup = `
  <div class="wallos-page-transition" id="wallos-page-transition" aria-hidden="true">
    <div class="wallos-page-transition-backdrop"></div>
    <div class="wallos-page-transition-grid"></div>
    <div class="wallos-page-transition-panel wallos-page-transition-panel-left"></div>
    <div class="wallos-page-transition-panel wallos-page-transition-panel-right"></div>
    <div class="wallos-page-transition-panel wallos-page-transition-panel-top"></div>
    <div class="wallos-page-transition-panel wallos-page-transition-panel-bottom"></div>
    <div class="wallos-page-transition-beam"></div>
    <div class="wallos-page-transition-rings" aria-hidden="true">
      <span></span><span></span><span></span>
    </div>

    <div class="wallos-page-transition-bluearchive-layer" aria-hidden="true">
      <div class="wallos-page-transition-ba-panel wallos-page-transition-ba-panel-left"></div>
      <div class="wallos-page-transition-ba-panel wallos-page-transition-ba-panel-right"></div>
      <div class="wallos-page-transition-ba-header-bar"></div>
      <div class="wallos-page-transition-ba-footer-bar"></div>
      <div class="wallos-page-transition-ba-gridline wallos-page-transition-ba-gridline-a"></div>
      <div class="wallos-page-transition-ba-gridline wallos-page-transition-ba-gridline-b"></div>
      <div class="wallos-page-transition-ba-corner wallos-page-transition-ba-corner-top-left"></div>
      <div class="wallos-page-transition-ba-corner wallos-page-transition-ba-corner-top-right"></div>
      <div class="wallos-page-transition-ba-corner wallos-page-transition-ba-corner-bottom-left"></div>
      <div class="wallos-page-transition-ba-corner wallos-page-transition-ba-corner-bottom-right"></div>
      <div class="wallos-page-transition-ba-triangles">
        <span></span><span></span><span></span>
      </div>
      <div class="wallos-page-transition-ba-datapanel">
        <span></span><span></span><span></span>
      </div>
      <div class="wallos-page-transition-ba-hud-card wallos-page-transition-ba-hud-card-left">
        <span class="wallos-page-transition-ba-label">ARCHIVE TERMINAL HANDSHAKE</span>
        <strong>01</strong>
      </div>
      <div class="wallos-page-transition-ba-hud-card wallos-page-transition-ba-hud-card-right">
        <span class="wallos-page-transition-ba-label">LINK / TACTICAL UI</span>
        <strong>UI</strong>
      </div>
      <div class="wallos-page-transition-ba-crosshair">
        <span></span><span></span><span></span>
      </div>
    </div>

    <div class="wallos-page-transition-center">
      <p class="wallos-page-transition-kicker">WALLOS // REMASTERED</p>
      <h2 class="wallos-page-transition-title">Subscriptions</h2>
      <p class="wallos-page-transition-subtitle">Loading the next scene</p>
      <div class="wallos-page-transition-progress" aria-hidden="true"><span></span></div>
      <p class="wallos-page-transition-status">Initializing dynamic scene</p>
      <p class="wallos-page-transition-accent">Visual Sync / Background Render / Scene Wipe</p>
    </div>
  </div>
`;

const fixtureHtml = `<!doctype html>
<html>
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
      :root {
        --accent-color-rgb: 124, 92, 255;
        --background-color-rgb: 13, 18, 28;
        --box-background-color-rgb: 242, 247, 255;
        --feedback-surface-rgb: 235, 244, 255;
        --main-color-rgb: 37, 146, 255;
        --overlay-backdrop-rgb: 10, 17, 30;
        --page-transition-backdrop-rgb: 13, 18, 28;
        --page-transition-copy-rgb: 242, 248, 255;
        --page-transition-highlight-rgb: 126, 211, 255;
        --page-transition-main-rgb: 37, 146, 255;
        --page-transition-shadow-rgb: 5, 14, 28;
        --page-transition-surface-rgb: 28, 40, 62;
        --text-color-inverted-rgb: 255, 255, 255;
        --wallos-dynamic-text-color-rgb: 246, 250, 255;
      }

      html,
      body {
        margin: 0;
        min-height: 300vh;
      }
    </style>
  </head>
  <body>${overlayMarkup}</body>
</html>`;

const freezeMotionCss = `
  *,
  *::before,
  *::after {
    animation: none !important;
    transition: none !important;
  }
`;

function isApproximately(actual, expected, tolerance = centerTolerancePx) {
  return Math.abs(actual - expected) <= tolerance;
}

function formatCase(testCase) {
  return `${testCase.style}/${testCase.viewport.name}/${testCase.state}/${testCase.direction}`;
}

let browser;
try {
  browser = await chromium.launch({ headless: true });
} catch (error) {
  const message = String(error?.message || error);
  if (/executable doesn['’]t exist|playwright install|browser executable/i.test(message)) {
    console.error("SKIP: Playwright Chromium is not installed. Run `npx playwright install chromium` before this check.");
    process.exit(77);
  }
  throw error;
}

let completedCases = 0;
try {
  const context = await browser.newContext();
  const page = await context.newPage();

  for (const viewport of viewports) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });

    for (const style of styles) {
      for (const state of states) {
        const direction = style === "bluearchive_theme" && viewport.name === "phone"
          ? "rtl"
          : "ltr";
        const testCase = { viewport, style, state, direction };
        const caseName = formatCase(testCase);

        await page.setContent(fixtureHtml, { waitUntil: "load" });
        await page.addStyleTag({ content: transitionCss });
        await page.addStyleTag({ content: freezeMotionCss });
        await page.evaluate(({ transitionStyle, transitionState, documentDirection }) => {
          document.documentElement.dataset.pageTransitionStyle = transitionStyle;
          document.documentElement.dir = documentDirection;
          document.documentElement.className = [
            "wallos-page-transition-enabled",
            `wallos-page-transition-${transitionState}`,
          ].join(" ");
          window.scrollTo(0, 173);
        }, {
          transitionStyle: style,
          transitionState: state,
          documentDirection: direction,
        });
        await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => resolve())));

        const geometry = await page.evaluate(() => {
          const overlay = document.querySelector(".wallos-page-transition");
          const center = document.querySelector(".wallos-page-transition-center");
          const overlayRect = overlay.getBoundingClientRect();
          const centerRect = center.getBoundingClientRect();
          const overlayStyle = getComputedStyle(overlay);
          const centerStyle = getComputedStyle(center);
          const viewport = {
            width: document.documentElement.clientWidth,
            height: document.documentElement.clientHeight,
          };

          const inspect = (selector) => {
            const element = document.querySelector(selector);
            const rect = element.getBoundingClientRect();
            const computed = getComputedStyle(element);
            return {
              selector,
              display: computed.display,
              visibility: computed.visibility,
              opacity: Number.parseFloat(computed.opacity),
              rect: {
                left: rect.left,
                top: rect.top,
                right: rect.right,
                bottom: rect.bottom,
                width: rect.width,
                height: rect.height,
                centerX: rect.left + (rect.width / 2),
                centerY: rect.top + (rect.height / 2),
              },
            };
          };

          return {
            viewport,
            scrollY: window.scrollY,
            documentScrollWidth: document.documentElement.scrollWidth,
            bodyScrollWidth: document.body.scrollWidth,
            overlay: {
              display: overlayStyle.display,
              visibility: overlayStyle.visibility,
              opacity: Number.parseFloat(overlayStyle.opacity),
              position: overlayStyle.position,
              rect: {
                left: overlayRect.left,
                top: overlayRect.top,
                right: overlayRect.right,
                bottom: overlayRect.bottom,
                width: overlayRect.width,
                height: overlayRect.height,
              },
            },
            center: {
              textAlign: centerStyle.textAlign,
              visibility: centerStyle.visibility,
              opacity: Number.parseFloat(centerStyle.opacity),
              rect: {
                left: centerRect.left,
                top: centerRect.top,
                right: centerRect.right,
                bottom: centerRect.bottom,
                width: centerRect.width,
                height: centerRect.height,
                centerX: centerRect.left + (centerRect.width / 2),
                centerY: centerRect.top + (centerRect.height / 2),
              },
            },
            blueArchiveLayer: inspect(".wallos-page-transition-bluearchive-layer"),
            blueArchiveDecorations: [
              ".wallos-page-transition-ba-panel-left",
              ".wallos-page-transition-ba-panel-right",
              ".wallos-page-transition-ba-hud-card-left",
              ".wallos-page-transition-ba-hud-card-right",
              ".wallos-page-transition-ba-corner-top-left",
              ".wallos-page-transition-ba-corner-top-right",
              ".wallos-page-transition-ba-corner-bottom-left",
              ".wallos-page-transition-ba-corner-bottom-right",
              ".wallos-page-transition-ba-crosshair",
            ].map(inspect),
            shutterRings: Array.from(
              document.querySelectorAll(".wallos-page-transition-rings span"),
              (element, index) => {
                const rect = element.getBoundingClientRect();
                const computed = getComputedStyle(element);
                return {
                  index,
                  display: computed.display,
                  visibility: computed.visibility,
                  opacity: Number.parseFloat(computed.opacity),
                  rect: {
                    width: rect.width,
                    height: rect.height,
                    centerX: rect.left + (rect.width / 2),
                    centerY: rect.top + (rect.height / 2),
                  },
                };
              },
            ),
          };
        });

        const expectedCenterX = geometry.viewport.width / 2;
        const expectedCenterY = geometry.viewport.height / 2;

        assert.ok(geometry.scrollY > 0, `${caseName}: fixture must be scrolled to test fixed positioning`);
        assert.equal(geometry.overlay.position, "fixed", `${caseName}: overlay must stay fixed to the viewport`);
        assert.notEqual(geometry.overlay.display, "none", `${caseName}: overlay must be rendered`);
        assert.equal(geometry.overlay.visibility, "visible", `${caseName}: overlay must be visible`);
        assert.ok(
          isApproximately(geometry.overlay.opacity, 1, opacityTolerance),
          `${caseName}: overlay opacity was ${geometry.overlay.opacity}, expected approximately 1`,
        );
        assert.ok(
          isApproximately(geometry.overlay.rect.left, 0)
            && isApproximately(geometry.overlay.rect.top, 0)
            && isApproximately(geometry.overlay.rect.width, geometry.viewport.width)
            && isApproximately(geometry.overlay.rect.height, geometry.viewport.height),
          `${caseName}: overlay does not cover the viewport: ${JSON.stringify(geometry.overlay.rect)}`,
        );

        assert.ok(
          isApproximately(geometry.center.rect.centerX, expectedCenterX)
            && isApproximately(geometry.center.rect.centerY, expectedCenterY),
          `${caseName}: center was (${geometry.center.rect.centerX}, ${geometry.center.rect.centerY}), `
            + `expected (${expectedCenterX}, ${expectedCenterY}) within ${centerTolerancePx}px`,
        );
        assert.equal(geometry.center.textAlign, "center", `${caseName}: transition copy must be center-aligned`);
        assert.equal(geometry.center.visibility, "visible", `${caseName}: transition copy must be visible`);
        assert.ok(
          isApproximately(geometry.center.opacity, 1, opacityTolerance),
          `${caseName}: transition copy opacity was ${geometry.center.opacity}, expected approximately 1`,
        );
        assert.ok(
          geometry.center.rect.left >= -centerTolerancePx
            && geometry.center.rect.top >= -centerTolerancePx
            && geometry.center.rect.right <= geometry.viewport.width + centerTolerancePx
            && geometry.center.rect.bottom <= geometry.viewport.height + centerTolerancePx,
          `${caseName}: transition copy escaped the viewport: ${JSON.stringify(geometry.center.rect)}`,
        );
        assert.ok(
          geometry.documentScrollWidth <= geometry.viewport.width + centerTolerancePx
            && geometry.bodyScrollWidth <= geometry.viewport.width + centerTolerancePx,
          `${caseName}: overlay created horizontal overflow `
            + `(document=${geometry.documentScrollWidth}, body=${geometry.bodyScrollWidth}, viewport=${geometry.viewport.width})`,
        );

        if (style === "shutter") {
          assert.equal(geometry.shutterRings.length, 3, `${caseName}: shutter must render three rings`);
          for (const ring of geometry.shutterRings) {
            assert.notEqual(ring.display, "none", `${caseName}: shutter ring ${ring.index} must render`);
            assert.equal(ring.visibility, "visible", `${caseName}: shutter ring ${ring.index} must be visible`);
            assert.ok(ring.opacity > 0, `${caseName}: shutter ring ${ring.index} must have non-zero opacity`);
            assert.ok(ring.rect.width > 0 && ring.rect.height > 0, `${caseName}: shutter ring ${ring.index} must have size`);
            assert.ok(
              isApproximately(ring.rect.centerX, expectedCenterX)
                && isApproximately(ring.rect.centerY, expectedCenterY),
              `${caseName}: shutter ring ${ring.index} is not centered: ${JSON.stringify(ring.rect)}`,
            );
          }
        } else {
          assert.notEqual(
            geometry.blueArchiveLayer.display,
            "none",
            `${caseName}: Blue Archive decoration layer must render`,
          );
          assert.equal(
            geometry.blueArchiveLayer.visibility,
            "visible",
            `${caseName}: Blue Archive decoration layer must be visible`,
          );
          assert.ok(
            isApproximately(geometry.blueArchiveLayer.opacity, 1, opacityTolerance),
            `${caseName}: Blue Archive layer opacity was ${geometry.blueArchiveLayer.opacity}`,
          );

          for (const decoration of geometry.blueArchiveDecorations) {
            assert.notEqual(decoration.display, "none", `${caseName}: ${decoration.selector} must render`);
            assert.equal(decoration.visibility, "visible", `${caseName}: ${decoration.selector} must be visible`);
            assert.ok(
              isApproximately(decoration.opacity, 1, opacityTolerance),
              `${caseName}: ${decoration.selector} opacity was ${decoration.opacity}, expected approximately 1`,
            );
            assert.ok(
              decoration.rect.width > 0 && decoration.rect.height > 0,
              `${caseName}: ${decoration.selector} must have visible geometry`,
            );
          }

          const crosshair = geometry.blueArchiveDecorations.find(
            (decoration) => decoration.selector === ".wallos-page-transition-ba-crosshair",
          );
          assert.ok(
            isApproximately(crosshair.rect.centerX, expectedCenterX)
              && isApproximately(crosshair.rect.centerY, expectedCenterY),
            `${caseName}: Blue Archive crosshair is not centered: ${JSON.stringify(crosshair.rect)}`,
          );
        }

        completedCases += 1;
        console.log(`PASS ${caseName}`);
      }
    }
  }

  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.setContent(fixtureHtml, { waitUntil: "load" });
  await page.addStyleTag({ content: transitionCss });
  await page.evaluate(() => {
    document.documentElement.dataset.pageTransitionStyle = "bluearchive_theme";
    document.documentElement.className = [
      "wallos-page-transition-enabled",
      "wallos-page-transition-revealed",
    ].join(" ");
  });

  const revealTransitionDurations = await page.evaluate(() => {
    const selectors = [
      ".wallos-page-transition-ba-panel-left",
      ".wallos-page-transition-ba-panel-right",
      ".wallos-page-transition-ba-header-bar",
      ".wallos-page-transition-ba-footer-bar",
      ".wallos-page-transition-ba-hud-card",
      ".wallos-page-transition-ba-crosshair",
      ".wallos-page-transition-ba-corner",
      ".wallos-page-transition-ba-triangles",
      ".wallos-page-transition-ba-datapanel",
    ];

    return selectors.map((selector) => ({
      selector,
      durations: getComputedStyle(document.querySelector(selector)).transitionDuration,
    }));
  });

  for (const transition of revealTransitionDurations) {
    const hasNonZeroDuration = transition.durations
      .split(",")
      .some((duration) => Number.parseFloat(duration) > 0);
    assert.ok(
      hasNonZeroDuration,
      `revealed: ${transition.selector} lost its transition before the reveal animation completed`,
    );
  }
  console.log("PASS bluearchive/revealed/transition-continuity");

  await context.close();
} finally {
  await browser.close();
}

console.log(
  `PASS: ${completedCases} page-transition geometry cases; center error <= ${centerTolerancePx}px.`,
);
