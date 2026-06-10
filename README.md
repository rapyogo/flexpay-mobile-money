# FlexPay Mobile Money — Integration Skill 🚀

**Un guide complet pour intégrer les paiements Mobile Money en RDC dans vos applications PHP et Next.js.**

[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-blue)](https://php.net)
[![Next.js](https://img.shields.io/badge/Next.js-14%2B-black)](https://nextjs.org)

---

## 🇨🇩 Contexte

FlexPay.cd est la passerelle de paiement de référence en RDC. Elle supporte :
- **Mobile Money** : Airtel Money, M-Pesa, AfriMoney, Orange Money
- **Carte bancaire** : Visa, Mastercard
- **Merchant Pay Out** : Transfert vers un compte Mobile Money

Ce repo contient le **skill d'intégration complet** — du schéma de base de données jusqu'au déploiement en production, y compris la sécurité.

---

## 🔄 Flux de paiement

```
Acheteur → Formulaire checkout → API FlexPay → Push téléphone → Confirmation
                                                              ↓
                       Callback ou Polling → Finalisation → Email + QR Code
```

---

## 📦 Ce que contient ce repo

| Dossier | Contenu |
|---------|---------|
| [`SKILL.md`](./SKILL.md) | Skill complet (1 600+ lignes) — architecture, code, sécurité, checklist |
| [`examples/php/`](./examples/php/) | Service PHP prêt à copier (FlexPayService + Controller + Callback) |
| [`examples/nextjs/`](./examples/nextjs/) | Route Handlers + Client Components Next.js 14+ App Router |

---

## 🚀 Démarrage rapide

### PHP (pur, sans framework)

```bash
# 1. Copier les fichiers
cp examples/php/FlexPayService.php app/Services/
cp examples/php/CheckoutController.php app/Controllers/
cp examples/php/CallbackController.php app/Controllers/

# 2. Configurer .env
FLEXPAY_MERCHANT_CODE=SIMULATED   # SIMULATED pour tester, vrai code en prod
FLEXPAY_API_URL=https://backend.flexpay.cd/api/rest/v1
FLEXPAY_API_TOKEN=votre_token_jwt
FLEXPAY_CALLBACK_URL=https://votredomaine.cd/callback/flexpay

# 3. Créer les tables SQL (voir SKILL.md § Database Schema)

# 4. Tester
curl -X POST http://localhost:8765/checkout/mon-event/start \
  -d "csrf_token=..." -d "phone=0812345678" -d "quantity=1"
```

### Next.js 14+

```bash
# 1. Copier le service
cp examples/nextjs/flexpay.ts lib/flexpay.ts
cp examples/nextjs/finalize.ts lib/finalize.ts

# 2. Ajouter les Route Handlers
cp -r examples/nextjs/api app/api/

# 3. Configurer .env.local
FLEXPAY_MERCHANT_CODE=SIMULATED
FLEXPAY_API_URL=https://backend.flexpay.cd/api/rest/v1
FLEXPAY_API_TOKEN=votre_token_jwt
FLEXPAY_CALLBACK_URL=http://localhost:3000/api/callback/flexpay

# 4. Lancer
npm run dev
# → http://localhost:3000/checkout/mon-event
```

---

## 🛡️ Sécurité

- ✅ CSRF sur tous les formulaires de paiement
- ✅ Vérification d'authenticité des callbacks (IP whitelist + token partagé)
- ✅ Protection anti-replay (transition atomique `pending → completed`)
- ✅ SSL vérifié sur toutes les requêtes FlexPay
- ✅ `import "server-only"` pour éviter l'exposition du token en Next.js
- ✅ Log d'audit obligatoire pour chaque interaction

---

## 📖 Lecture recommandée

Lire d'abord [`SKILL.md`](./SKILL.md) — c'est le document canonique qui couvre l'intégralité du sujet.

---

## 🤝 Contribuer

Les PRs sont les bienvenues ! Voir [CONTRIBUTING.md](.github/CONTRIBUTING.md).

Si vous avez intégré FlexPay dans un autre langage (Python/Django, Laravel, Node.js...), partagez votre implémentation !

---

## 📄 Licence

MIT — utilisez ce code librement dans vos projets commerciaux ou open-source.

---

**Développé à partir de l'expérience production de [TIXYA](https://tixya.online) — Première plateforme de billetterie et de gestion événementielle en ligne en RDC.**
