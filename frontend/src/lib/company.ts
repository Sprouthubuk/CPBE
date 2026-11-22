// The company behind the site, shown in the footer, on Free Postal Entry and on Contact.
// One place to change. Every value marked TBC is waiting for Liam (Phase 3): never guess these.
// A launch build (PUBLIC_SEARCH_INDEXING=on) refuses to start while any value is still TBC (astro.config.mjs).

export const COMPANY = {
  tradingName: "Brand Name",
  legalName: "[Company name TBC]",
  number: "[TBC]",
  registeredOffice: ["[Registered office TBC]"],
  // Where free postal entries go (shown on /free-entry and Contact).
  postalAddress: ["[Postal entry address TBC]"],
} as const;

export const companyIsConfirmed = !JSON.stringify(COMPANY).includes("TBC");

export const postalLines = (): string[] => [COMPANY.legalName, ...COMPANY.postalAddress];
