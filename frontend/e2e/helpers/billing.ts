/** What GET /api/admin/billing answers for a company on Free (BillingController::overview()). */
export const FREE_OVERVIEW = {
  state: 'free',
  planTier: 'free',
  seatLimit: 100,
  activeUsers: 1,
  pendingInvites: 0,
  periodEndsAt: null,
  cancelsAt: null,
  hasInvoices: false,
  freeSeatLimit: 100,
  plusSeatLimit: 1000,
  supportEmail: null,
};
