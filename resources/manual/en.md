# User manual · CLARTO Gestión Inmobiliaria

This guide explains day-to-day work with the panel. Each property is managed **only once**, here: what you publish in the
panel is what appears on **clarto.es/inmuebles** and, once activated, on the portals.

## 1. Getting started

**Signing in.** Open https://panel.clarto.es and sign in with your e-mail address and password. If the administrator has
enabled Google, you can also click **Continue with Google** (you must first connect it once from your profile, see 7.3).

**Your first password.** When an administrator registers you, you receive an e-mail **"Your access to CLARTO Gestión
Inmobiliaria"** with the button **Create my password**. The link works only once and expires after 24 hours. If it expires,
ask the administrator to send you another.

**Forgotten your password?** On the sign-in screen click **Forgotten your password?** and you will receive a link.

**Language.** At the top right is the **ES / EN / TR** selector. The panel remembers each user's language.

**The menu.** On the left: **Sales & lettings** (Properties, Listings, Viewings, Offers…), **People & relationships**,
**Marketing & portals**, **Organisation** (users and roles) and **Platform settings** (administrators only).
At the top, **Search** finds properties, contacts and more by name or reference. The icon at the top left
collapses the menu.

## 2. Adding a property

1. Menu **Sales & lettings → Properties → New property**.
2. **Basic information** tab: **Deal type** (sale or letting) and **Property type** are required.
3. **Public texts → Spanish** tab: **Title** and **Long description** (what the client will see). See section 4.
4. **Address and legal details** tab: the **address** is required but **never published**; the website shows only
   the area. Choose **Region**, **City** and **District**: these are what the website's filters use.
5. **Price and features** tab: **price**, **bedrooms**, **bathrooms** and **Built area (m²)**.
6. **Description** tab: **Internal notes**, which only the team can see.
7. Click **Create**. The property starts as a **Draft**: it is not yet visible on the website.
8. Open it again and add the photos (section 3).

The **reference** (for example **CL-2026-00012**) is assigned by the panel. Use it when speaking to clients: it appears on the website, in
WhatsApp and in viewing requests.

## 3. Photos and plans

In the property record, **Photos and plans** tab:

* **Drag** the photos into the box or click to choose them (JPG, PNG or WebP, up to 15 MB each; on a mobile phone
  you can choose from the gallery).
* **Drag** the thumbnails to reorder them. **The first one is the cover photo** on the website.
* To remove a photo, click the **X** on its thumbnail.
* The **plans** go in their own box and are shown separately on the website's property page.
* Click **Save changes**. The website automatically creates lightweight versions of each photo.

Tip: landscape, bright photos with no people or number plates. iPhone photos in HEIC format are not accepted:
on the iPhone, go to **Settings → Camera → Formats → Most Compatible**.

## 4. Public texts and translations (ES · EN · TR)

In the property record, **Public texts** tab, there is one tab per language. Each one has **Title**, **Short description**,
**Long description**, **SEO title** and **SEO description**.

* **Spanish** is the original. Anything missing in English or Turkish is shown in Spanish.
* The orange counter (**"2 pending"**) shows how many fields are missing in that language.
* Each language is saved with **Save changes**, independently of the others.
* Publishing in Spanish does **not** require English or Turkish.

### With artificial intelligence

* **Generate ES · EN · TR with AI**: writes the Spanish text from the property data and translates it into
  English and Turkish.
* **Translate approved Spanish to EN and TR**: when the Spanish text is already right, it only translates.
* In each language, **Write draft…** or **Translate to … with AI** redoes that language only.

The AI **never publishes**: it leaves an **AI draft (not published)** below each language's fields.

1. Read it and correct it in the draft itself. If **"Check: Figures not in the property data…"** or **"Does not mention…"** appears,
   check those points.
2. Click **Approve draft**: it becomes the official text for that language. Or **Discard draft**.

If the approved Spanish text changes, the translations show **"The Spanish text changed…: review it"**. Review the
translation and click **Mark as reviewed**.

If **"The AI quota is used up"** appears, wait a few minutes and try again: anything already generated remains
saved.

## 5. Publishing, reserving and withdrawing

Status changes are made in **Properties**, using the **⋮** menu on each row:

| Status | Visible on the website? | How it is reached |
|---|---|---|
| **Draft** | No | when the property is created |
| **Available** | Yes | **Publish** (from Draft) or **Release reservation** |
| **Under offer** | Yes, with the "Under offer" label | **Mark under offer** |
| **Sold / Let** | No | **Mark sold** / **Mark as let** |
| **Withdrawn** | No | **Withdraw** |

* The website updates immediately: when a property is withdrawn or sold, its page is no longer shown and its link displays
  «Este inmueble ya no está publicado».
* **Withdrawing is final**: a withdrawn property cannot be published again. If you only want to hide it for a while,
  mark it as **Under offer** or leave it as a **Draft** before publishing it.
* What never appears on the website: exact address, internal notes, owner and the owner's contact details.

## 6. Portals: pisos.com

In the property record, **Portals** tab:

* **Publish on pisos.com**, **Update**, **Withdraw** and **Check status**.
* You can see the **status** (Not sent, Sent (not confirmed), Accepted by the portal, Published, Rejected, Withdrawn from the portal), the **pisos.com id**, the
  **last synchronisation** and the **reason** if the portal rejects it.
* **Missing before sending** lists what needs to be completed.
* Withdrawing from the portal does **not** affect the property in the panel.

At present, real submissions are **disabled** (you will see "simulation"): they will be enabled once CLARTO has the contract and the
pisos.com keys.

## 7. Users and access (administrators)

### 7.1 Registering a person

**Organisation → Users → New user**: name, e-mail address and role. On creation, the person receives the e-mail to **create
their password**. Nobody types in another person's password.

Usual roles: **admin** (the whole panel), **staff** (day-to-day work). Fine-grained permissions are in **Organisation → Roles**.

### 7.2 Resending access

In **Users**, open the record and click **Send access link** (also in the ⋮ menu of the list). The person receives
a link to choose a new password.

### 7.3 Connecting Google

Each person, while signed in: **user icon → Profile → Connected accounts → Google**. You can only connect
a Google account whose e-mail address **matches** the one in the panel. You can then use **Continue with Google** when signing in.

## 8. Platform settings (administrators)

* **Outgoing mail**: the mailbox from which the panel sends invitations and notifications (for example info@clarto.es on
  Hostinger: smtp.hostinger.com, port 465, SSL). Use **Send test e-mail** after saving.
* **Artificial intelligence**: OpenRouter key, writing and translation models, and **Test connection**. The
  paid fallback is only used if you expressly authorise it.
* **Site settings**: name, appearance and general details.

## 9. Frequently asked questions

**I have published it and cannot see it on the website.** Check that the status is **Available** or **Under offer** and reload
clarto.es/inmuebles. If the title is empty, the website uses the type and the area.

**How do I change the price?** Edit the property, change the price and save: the website updates immediately.

**Can I undo "Withdraw"?** No. Create the property again if it comes back on the market.

**The access e-mail has not arrived.** Check your spam folder and ask the administrator to click **Send access link**.

**What does the client see on the website?** Title, descriptions, price, floor area, bedrooms, bathrooms, photos, plans, approximate
location and the reference. To request a viewing they use the form or WhatsApp, always quoting the reference.
