## Messaging in the new back office
- **Saved credentials never leave the server.** The set-up answer carries, for each credential box, only whether one is saved; an empty box keeps it (ProviderSetup, as now). A test checks that saved keys and tokens do not appear anywhere in the answer. The WhatsApp verify token and callback address are shown, as now, because they are typed at Meta.
- **Try again stays as it is:** whoever may see the message log can queue a failed or not-sent message again (the relay sends it). The server now refuses one that is not failed or not sent.
- **Templates open in a dialog** with the same fields as now; archiving asks why, as on the other lists.
