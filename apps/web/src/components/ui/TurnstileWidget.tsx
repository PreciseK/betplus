"use client";

import React, { useEffect, useRef } from "react";

interface TurnstileWidgetProps {
  onSuccess?: (token: string) => void;
  onError?: () => void;
  className?: string;
}

declare global {
  interface Window {
    turnstile?: {
      render: (
        container: HTMLElement,
        options: {
          sitekey: string;
          callback: (token: string) => void;
          "error-callback"?: () => void;
          "expired-callback"?: () => void;
          theme?: "light" | "dark" | "auto";
          size?: "normal" | "compact" | "invisible";
        },
      ) => string;
      reset: (widgetId: string) => void;
      remove: (widgetId: string) => void;
    };
    __betplus_turnstile_token?: string;
  }
}

export function TurnstileWidget({ onSuccess, onError, className }: TurnstileWidgetProps) {
  const containerRef = useRef<HTMLDivElement>(null);
  const widgetIdRef = useRef<string | null>(null);
  const siteKey = process.env.NEXT_PUBLIC_CLOUDFLARE_TURNSTILE_SITE_KEY || "";

  useEffect(() => {
    if (!siteKey || !containerRef.current) {
      return;
    }

    const scriptId = "cf-turnstile-script";

    const renderWidget = () => {
      if (!window.turnstile || !containerRef.current || widgetIdRef.current) {
        return;
      }

      try {
        widgetIdRef.current = window.turnstile.render(containerRef.current, {
          sitekey: siteKey,
          theme: "dark",
          size: "normal",
          callback: (token: string) => {
            window.__betplus_turnstile_token = token;
            if (onSuccess) {
              onSuccess(token);
            }
          },
          "error-callback": () => {
            window.__betplus_turnstile_token = undefined;
            if (onError) {
              onError();
            }
          },
          "expired-callback": () => {
            window.__betplus_turnstile_token = undefined;
          },
        });
      } catch (e) {
        console.error("Turnstile render error:", e);
      }
    };

    if (window.turnstile) {
      renderWidget();
    } else if (!document.getElementById(scriptId)) {
      const script = document.createElement("script");
      script.id = scriptId;
      script.src = "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";
      script.async = true;
      script.defer = true;
      script.onload = () => {
        renderWidget();
      };
      document.head.appendChild(script);
    } else {
      const existingScript = document.getElementById(scriptId);
      if (existingScript) {
        existingScript.addEventListener("load", renderWidget);
      }
    }

    return () => {
      if (widgetIdRef.current && window.turnstile) {
        try {
          window.turnstile.remove(widgetIdRef.current);
        } catch {
          // ignore
        }
        widgetIdRef.current = null;
      }
    };
  }, [siteKey, onSuccess, onError]);

  if (!siteKey) {
    return null;
  }

  return (
    <div className={className || "my-3 flex justify-center"}>
      <div ref={containerRef} />
    </div>
  );
}
