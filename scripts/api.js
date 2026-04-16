(function (window) {
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
    return error;
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
        throw createFallbackRequestError(
          extractResponseMessage(data, effectiveErrorMessage || translateFallback("network_response_error", "Network response error")),
          {
            response,
            status: response.status,
            data,
            rawBody: parsedResponse.rawBody,
          }
        );
      }

      if (checkSuccess && (!data || data[successField] !== true)) {
        throw createFallbackRequestError(
          extractResponseMessage(data, effectiveErrorMessage || translateFallback("error", "Error")),
          {
            response,
            status: response.status,
            data,
            rawBody: parsedResponse.rawBody,
          }
        );
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
    };
  }

  const existingHttp = window.WallosHttp;
  window.WallosApi = existingHttp
    ? createWallosApiFromExisting(existingHttp)
    : createStandaloneWallosApi();
})(window);
