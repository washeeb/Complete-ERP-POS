## Ask your business, Alerts and the AI usage log in the new back office
- **The chat lives in the page, not on the server:** as the current page, each question is sent with the chat so far, and New chat (or leaving the page) starts again. Nothing about the conversation is stored except the AI usage log row the gateway already writes.
- **An answer that cannot be given is a note in the chat, not an error:** AI off or over budget, or a question it cannot answer, comes back as the same note as now, pointing to the quick questions.
- **Refresh now stays company-wide only**, checked on the server, as the current button.
- **office-check finds menu links with a "99+" badge** (Alerts showed "Alerts 99+", which the link matcher did not allow, so the step was skipped).
