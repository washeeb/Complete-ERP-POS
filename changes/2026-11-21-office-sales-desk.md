### Added
- **Till requests, Back-office returns, Review queue and Gift cards & credit rebuilt in the new back office (part 16).** Till requests: what tills ask a manager for, kept fresh every few seconds, Approve (confirming it is you) or Decline with why, and Turn on notifications for this phone. Back-office returns: the list, one return with Approve and refund, Cancel and the return slip; **Return goods** on a sale's page starts one. Review queue: open, resolved or all, by type, the sale behind one, Resolve. Gift cards & credit: balances, history, Sell gift card.

### Fixed
- **Windows servers: phone notifications (till requests) could not be turned on or sent.** PHP on Windows comes without an OpenSSL configuration, and without one it cannot make the keys notifications need ("Unable to create the key"). The notification key is now made with a configuration file shipped with the server, and the Windows installer points PHP at that file (`OPENSSL_CONF`) so each notification can be sent. Linux and Docker were not affected.

### Changed
- **Back-office returns lists only the branches where the person enters or approves returns** (the current page showed every branch to anyone who may do either anywhere).
