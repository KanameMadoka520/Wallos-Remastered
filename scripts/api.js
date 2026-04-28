(function (window) {
  function isSessionFailurePayload(data) {
    return Boolean(
      data
      && typeof data === "object"
      && (
        data.session_expired === true
        || data.code === "session_expired"
        || data.error === "session_expired"
        || data.requires_relogin === true
      )
    );
  }

  function isLoginHtmlResponse(rawBody) {
    const body = String(rawBody || "").toLowerCase();
    if (body === "" || (!body.includes("<html") && !body.includes("<!doctype html"))) {
      return false;
    }

    const hasLoginForm = body.includes('id="username"')
      && body.includes('id="password"')
      && body.includes("login.php");
    const hasLoginCopy = body.includes("please login")
      || body.includes("sign in")
      || body.includes("请登录")
      || body.includes("登入");

    return hasLoginForm || hasLoginCopy;
  }

  function isSessionFailureError(error) {
    return Boolean(
      error
      && typeof error === "object"
      && (error.sessionExpired === true || isSessionFailurePayload(error.data))
    );
  }

  function isCsrfFailurePayload(data) {
    if (!data || typeof data !== "object") {
      return false;
    }

    const code = String(data.code || data.error || "").toLowerCase();
    if (code === "invalid_csrf") {
      return true;
    }

    const message = String(data.message || "").toLowerCase();
    return message.includes("invalid csrf token");
  }

  function isCsrfFailureError(error) {
    return Boolean(
      error
      && typeof error === "object"
      && (error.csrfInvalid === true || isCsrfFailurePayload(error.data))
    );
  }

  function translateFallback(key, fallback) {
    if (typeof window.translate === "function") {
      const translated = window.translate(key);
      if (translated && translated !== "[Translation Missing]") {
        return translated;
      }
    }

    return fallback || key;
  }

  function createFallbackRequestError(message, context = {}) {
    const error = new Error(String(message || translateFallback("unknown_error", "Unknown error")));
    error.response = context.response || null;
    error.status = Number(context.status || context.response?.status || 0);
    error.data = context.data;
    error.rawBody = context.rawBody;
    error.cause = context.cause;
    error.code = context.code || (context.data && typeof context.data === "object"
      ? String(context.data.code || context.data.error || "")
      : "");
    error.sessionExpired = isSessionFailurePayload(context.data);
    error.csrfInvalid = isCsrfFailurePayload(context.data);
    return error;
  }

  function createSessionExpiredErrorFromHtml(response, rawBody) {
    const message = translateFallback("session_expired", "Session expired");
    return createFallbackRequestError(message, {
      response,
      status: Number(response?.status || 401) || 401,
      rawBody,
      data: {
        success: false,
        code: "session_expired",
        error: "session_expired",
        message,
        session_expired: true,
        requires_relogin: true,
        html_login_response: true,
      },
    });
  }

  function createWallosApiFromExisting(existingHttp) {
    function ensureJsonPayload(data, options = {}) {
      if (data === null || data === undefined) {
        throw new Error(options.fallbackErrorMessage || options.errorMessage || translateFallback("unknown_error", "Unknown error"));
      }

      return data;
    }

    return {
      requestJson(url, options = {}) {
        return (existingHttp.requestJson
          ? existingHttp.requestJson(url, options)
          : existingHttp.request(url, { ...options, responseType: "json" }).then((result) => result.data))
          .then((data) => ensureJsonPayload(data, options));
      },
      requestText(url, options = {}) {
        return existingHttp.request
          ? existingHttp.request(url, { ...options, responseType: "text" }).then((result) => result.data)
          : Promise.reject(new Error(translateFallback("unknown_error", "Unknown error")));
      },
      getJson(url, options = {}) {
        return (existingHttp.getJson
          ? existingHttp.getJson(url, options)
          : this.requestJson(url, { ...options, method: "GET" }))
          .then((data) => ensureJsonPayload(data, options));
      },
      getText(url, options = {}) {
        return this.requestText(url, { ...options, method: "GET" });
      },
      postJson(url, payload = {}, options = {}) {
        return (existingHttp.postJson
          ? existingHttp.postJson(url, payload, options)
          : this.requestJson(url, {
            ...options,
            method: "POST",
            headers: {
              ...(options.headers || {}),
              "Content-Type": "application/json",
            },
            body: JSON.stringify(payload ?? {}),
          }))
          .then((data) => ensureJsonPayload(data, options));
      },
      postForm(url, payload = {}, options = {}) {
        return (existingHttp.postForm
          ? existingHttp.postForm(url, payload, options)
          : this.requestJson(url, {
            ...options,
            method: "POST",
            body: payload instanceof URLSearchParams || payload instanceof FormData
              ? payload
              : new URLSearchParams(payload || {}),
          }))
          .then((data) => ensureJsonPayload(data, options));
      },
      getErrorMessage(error, fallbackMessage) {
        if (typeof existingHttp.normalizeError === "function") {
          return existingHttp.normalizeError(error, fallbackMessage);
        }

        if (error instanceof Error && error.message) {
          return error.message;
        }

        if (typeof error === "string" && error.trim() !== "") {
          return error.trim();
        }

        return fallbackMessage || translateFallback("error", "Error");
      },
      normalizeError(error, fallbackMessage) {
        return this.getErrorMessage(error, fallbackMessage);
      },
      isSessionFailurePayload(payload) {
        if (typeof existingHttp.isSessionFailurePayload === "function") {
          return existingHttp.isSessionFailurePayload(payload);
        }
        return isSessionFailurePayload(payload);
      },
      isLoginHtmlResponse,
      isSessionFailureError(error) {
        if (typeof existingHttp.isSessionFailureError === "function") {
          return existingHttp.isSessionFailureError(error);
        }
        return isSessionFailureError(error);
      },
      isCsrfFailurePayload(payload) {
        if (typeof existingHttp.isCsrfFailurePayload === "function") {
          return existingHttp.isCsrfFailurePayload(payload);
        }
        return isCsrfFailurePayload(payload);
      },
      isCsrfFailureError(error) {
        if (typeof existingHttp.isCsrfFailureError === "function") {
          return existingHttp.isCsrfFailureError(error);
        }
        return isCsrfFailureError(error);
      },
      showCsrfTokenRefreshReminder() {
        if (typeof existingHttp.showCsrfTokenRefreshReminder === "function") {
          return existingHttp.showCsrfTokenRefreshReminder();
        }
        return false;
      },
    };
  }

  function createStandaloneWallosApi() {
    function extractResponseMessage(data, fallbackMessage) {
      if (typeof data === "string" && data.trim() !== "") {
        return data.trim();
      }

      if (data && typeof data === "object") {
        if (typeof data.message === "string" && data.message.trim() !== "") {
          return data.message.trim();
        }

        if (typeof data.error === "string" && data.error.trim() !== "") {
          return data.error.trim();
        }
      }

      return fallbackMessage || "";
    }

    async function parseResponseBody(response, parseMode) {
      if (parseMode === "text") {
        return response.text();
      }

      const rawBody = await response.text();
      if (!rawBody) {
        return { data: {}, rawBody: "" };
      }

      try {
        return {
          data: JSON.parse(rawBody),
          rawBody,
        };
      } catch (error) {
        if (isLoginHtmlResponse(rawBody)) {
          const requestError = createSessionExpiredErrorFromHtml(response, rawBody);
          window.dispatchEvent(new CustomEvent("wallos:session-expired", { detail: requestError }));
          throw requestError;
        }

        throw createFallbackRequestError(translateFallback("unknown_error", "Unknown error"), {
          response,
          status: response.status,
          rawBody,
          cause: error,
        });
      }
    }

    async function request(url, options = {}) {
      const {
        method = "GET",
        headers = {},
        body,
        includeCsrf = true,
        credentials = "same-origin",
        parse = "json",
        checkHttp = true,
        requireOk,
        checkSuccess = false,
        successField = "success",
        errorMessage = "",
        fallbackErrorMessage = "",
      } = options;

      const shouldCheckHttp = typeof requireOk === "boolean" ? requireOk : checkHttp;
      const effectiveErrorMessage = fallbackErrorMessage || errorMessage;
      const requestHeaders = new Headers(headers || {});
      const isFormData = typeof FormData !== "undefined" && body instanceof FormData;

      if (includeCsrf && window.csrfToken && !requestHeaders.has("X-CSRF-Token")) {
        requestHeaders.set("X-CSRF-Token", window.csrfToken);
      }

      if (!isFormData && body !== undefined && body !== null && !requestHeaders.has("Content-Type")) {
        if (body instanceof URLSearchParams) {
          requestHeaders.set("Content-Type", "application/x-www-form-urlencoded;charset=UTF-8");
        } else if (typeof body === "string") {
          requestHeaders.set("Content-Type", "application/json");
        }
      }

      const response = await fetch(url, {
        method,
        headers: requestHeaders,
        body,
        credentials,
      });

      if (parse === "text") {
        const textData = await parseResponseBody(response, "text");
        if (shouldCheckHttp && !response.ok) {
          throw createFallbackRequestError(
            effectiveErrorMessage || response.statusText || translateFallback("network_response_error", "Network response error"),
            {
              response,
              status: response.status,
              data: textData,
            }
          );
        }

        return textData;
      }

      const parsedResponse = await parseResponseBody(response, "json");
      const data = parsedResponse.data;

      if (shouldCheckHttp && !response.ok) {
        const requestError = createFallbackRequestError(
          extractResponseMessage(data, effectiveErrorMessage || translateFallback("network_response_error", "Network response error")),
          {
            response,
            status: response.status,
            data,
            rawBody: parsedResponse.rawBody,
          }
        );
        if (isCsrfFailureError(requestError)) {
          window.dispatchEvent(new CustomEvent("wallos:csrf-invalid", { detail: requestError }));
        }
        throw requestError;
      }

      if (checkSuccess && (!data || data[successField] !== true)) {
        const requestError = createFallbackRequestError(
          extractResponseMessage(data, effectiveErrorMessage || translateFallback("error", "Error")),
          {
            response,
            status: response.status,
            data,
            rawBody: parsedResponse.rawBody,
          }
        );
        if (isCsrfFailureError(requestError)) {
          window.dispatchEvent(new CustomEvent("wallos:csrf-invalid", { detail: requestError }));
        }
        throw requestError;
      }

      return data;
    }

    return {
      requestJson(url, options = {}) {
        return request(url, { parse: "json", ...options });
      },
      requestText(url, options = {}) {
        return request(url, { parse: "text", ...options });
      },
      getJson(url, options = {}) {
        return this.requestJson(url, { method: "GET", ...options });
      },
      getText(url, options = {}) {
        return this.requestText(url, { method: "GET", ...options });
      },
      postJson(url, payload = {}, options = {}) {
        return this.requestJson(url, {
          method: "POST",
          body: JSON.stringify(payload ?? {}),
          ...options,
        });
      },
      postForm(url, payload = {}, options = {}) {
        const body = payload instanceof URLSearchParams || payload instanceof FormData
          ? payload
          : new URLSearchParams(payload || {});

        return this.requestJson(url, {
          method: "POST",
          body,
          ...options,
        });
      },
      getErrorMessage(error, fallbackMessage) {
        if (error instanceof Error && error.message) {
          return error.message;
        }

        if (typeof error === "string" && error.trim() !== "") {
          return error.trim();
        }

        return fallbackMessage || translateFallback("error", "Error");
      },
      normalizeError(error, fallbackMessage) {
        return this.getErrorMessage(error, fallbackMessage);
      },
      isSessionFailurePayload,
      isLoginHtmlResponse,
      isSessionFailureError,
      isCsrfFailurePayload,
      isCsrfFailureError,
    };
  }

  const existingHttp = window.WallosHttp;
  window.WallosApi = existingHttp
    ? createWallosApiFromExisting(existingHttp)
    : createStandaloneWallosApi();
})(window);
