# tools/gates

CI release gates: RTP ceiling check, prohibited-RNG scan, USSD screen length (≤160 chars),
and schema-drift detection between a fresh `migrate` and the live schema. Wired into
`.github/workflows/gates.yml`.
