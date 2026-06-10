/**
 * FlexPay Mobile Money Service — Next.js (App Router)
 *
 * Module SERVER-ONLY — ne jamais importer dans un Client Component.
 * Utilise l'API fetch() native au lieu de cURL.
 *
 * Usage dans un Route Handler ou Server Action :
 *   import { initiateMobileMoney, isSimulated } from "@/lib/flexpay";
 *   const resp = await initiateMobileMoney("0812345678", "REF-123", 25.00);
 *
 * Mode simulé : FLEXPAY_MERCHANT_CODE=SIMULATED dans .env.local
 */

import "server-only";

const API_URL = (
  process.env.FLEXPAY_API_URL || "https://backend.flexpay.cd/api/rest/v1"
).replace(/\/+$/, "");
const CHECK_URL = (
  process.env.FLEXPAY_CHECK_URL || `${API_URL}/check`
).replace(/\/+$/, "");
const MERCHANT_CODE = process.env.FLEXPAY_MERCHANT_CODE || "";
const CALLBACK_URL = process.env.FLEXPAY_CALLBACK_URL || "";

// ═══════════════════════════════════════════════════════════
// Helpers
// ═══════════════════════════════════════════════════════════

function token(): string {
  const raw = (process.env.FLEXPAY_API_TOKEN || "").trim();
  if (!raw) return "";
  return raw.toLowerCase().startsWith("bearer ") ? raw : `Bearer ${raw}`;
}

export function isSimulated(): boolean {
  return MERCHANT_CODE.toUpperCase() === "SIMULATED";
}

/**
 * Normalise un numéro de téléphone au format international 243XXXXXXXXX.
 */
export function normalizePhone(phone: string): string {
  const digits = phone.replace(/\D+/g, "");
  if (digits.startsWith("00")) return digits.slice(2);
  if (digits.startsWith("0")) return "243" + digits.slice(1);
  if (!digits.startsWith("243") && digits.length === 9) return "243" + digits;
  return digits;
}

/**
 * Génère une référence unique pour une transaction.
 */
export function buildReference(
  eventId: number,
  userId: number,
  extra = ""
): string {
  const rand = Array.from({ length: 6 }, () =>
    Math.floor(Math.random() * 16).toString(16)
  ).join("");
  return `EVT${eventId}-U${userId}-${Date.now()}-${rand}${extra}`;
}

// ═══════════════════════════════════════════════════════════
// Types
// ═══════════════════════════════════════════════════════════

export interface FlexPayInitResponse {
  ok: boolean;
  httpCode: number;
  error: string | null;
  data: {
    code: string;
    message: string;
    orderNumber: string;
  } | null;
  rawRequest: string;
  rawResponse: string;
}

export interface FlexPayCheckResponse {
  ok: boolean;
  httpCode: number;
  error: string | null;
  data: {
    code: string;
    message: string;
    transaction: {
      orderNumber: string;
      status: string;       // "0" = success, "1" = failed
      amount: string;
      channel: string;
    };
  } | null;
  rawRequest: string;
  rawResponse: string;
}

// ═══════════════════════════════════════════════════════════
// Core
// ═══════════════════════════════════════════════════════════

/**
 * Initie un paiement Mobile Money.
 * L'utilisateur recevra une notification push sur son téléphone.
 */
export async function initiateMobileMoney(
  phone: string,
  reference: string,
  amount: number,
  currency = "USD"
): Promise<FlexPayInitResponse> {
  if (isSimulated()) {
    const orderNumber = `SIM${Date.now()}${Math.random()
      .toString(36)
      .slice(2, 10)}`;
    return {
      ok: true,
      httpCode: 200,
      error: null,
      data: { code: "0", message: "Simulated", orderNumber },
      rawRequest: JSON.stringify({ simulated: true, reference }),
      rawResponse: JSON.stringify({ code: "0", orderNumber }),
    };
  }

  const body = {
    merchant: MERCHANT_CODE,
    type: "1",
    phone: normalizePhone(phone),
    reference,
    amount: String(amount),
    currency,
    callbackUrl: CALLBACK_URL,
  };

  const rawRequest = JSON.stringify(body);

  try {
    const res = await fetch(`${API_URL}/paymentService`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: token(),
        Accept: "application/json",
      },
      body: rawRequest,
      signal: AbortSignal.timeout(30_000),
    });

    const rawResponse = await res.text();
    const data = JSON.parse(rawResponse);

    return {
      ok: res.ok,
      httpCode: res.status,
      error: null,
      data,
      rawRequest,
      rawResponse,
    };
  } catch (err: any) {
    return {
      ok: false,
      httpCode: 0,
      error: err.message || "Network error",
      data: null,
      rawRequest,
      rawResponse: "",
    };
  }
}

/**
 * Vérifie le statut d'une transaction via l'orderNumber.
 */
export async function checkTransaction(
  orderNumber: string
): Promise<FlexPayCheckResponse> {
  if (isSimulated()) {
    return {
      ok: true,
      httpCode: 200,
      error: null,
      data: {
        code: "0",
        message: "Simulated",
        transaction: {
          orderNumber,
          status: "0",
          amount: "0",
          channel: "simulated",
        },
      },
      rawRequest: "",
      rawResponse: JSON.stringify({ simulated: true, orderNumber }),
    };
  }

  try {
    const res = await fetch(
      `${CHECK_URL}/${encodeURIComponent(orderNumber)}`,
      {
        headers: {
          Authorization: token(),
          Accept: "application/json",
        },
        signal: AbortSignal.timeout(15_000),
      }
    );

    const rawResponse = await res.text();
    const data = JSON.parse(rawResponse);

    return {
      ok: res.ok,
      httpCode: res.status,
      error: null,
      data,
      rawRequest: "",
      rawResponse,
    };
  } catch (err: any) {
    return {
      ok: false,
      httpCode: 0,
      error: err.message || "Network error",
      data: null,
      rawRequest: "",
      rawResponse: "",
    };
  }
}
