# PromptEdit reviewer guide

PromptEdit's **Buy Credits** workflow is simulated for this assessment. It does
not contact a payment provider and does not charge real money.

1. Open the supplied public HTTPS application URL.
2. Register a new account or sign in.
3. Open **Studio**.
4. Select **Buy Credits**, choose a simulated package, and confirm. An empty
   wallet can be funded without administrator intervention.
5. Enter an image prompt, select a supported size and quality, and optionally add
   up to four reference image URLs.
6. Review the displayed estimated cost.
7. Submit the generation. The estimated amount is reserved; a failed generation
   is not charged.
8. Keep Studio open to observe live queued/processing/completed updates. Provider
   callbacks require the deployed public HTTPS endpoint, so localhost is not a
   substitute for this journey.
9. When complete, view the privately served image and the final actual cost. The
   charge is provider cost plus the assessment's 25% markup.
10. Open **History** to confirm the generation, estimate, final cost, status, and
    image remain available and continue to update live.

For a security spot-check, sign in as a different user and confirm that copying
the first account's generation status/image URL does not reveal its generation or
private image. Do not use real credentials or payment information in PromptEdit.
