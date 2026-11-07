# Section 2.2 — Personal Seed Worksheet (PRIVATE VALUES NOT FILLED)

The instructor requires unique limits based on the **last two digits** of your student number.

- `D1` = second-to-last digit: `____`
- `D2` = last digit: `____`
- Do not publish your full student number to this public repository.

| Parameter | Exact handout formula | Your calculated value |
| --- | --- | --- |
| Minimum username length | `6 + (D1 mod 3)` | ____ |
| Maximum username length | `16 + (D2 mod 5)` | ____ |
| Minimum password length | `8 + (D2 mod 4)` | ____ |
| Maximum each name field | `20 + D2` | ____ |
| Failures before Forgot Password link | `2 + (D1 mod 2)` | ____ |
| Lockout tier 1 (seconds) | `15 + D1` | ____ |
| Lockout tier 2 (seconds) | `30 + (2 × D1)` | ____ |
| Lockout tier 3 (seconds) | `60 + (4 × D1)` | ____ |
| OTP expiry (minutes) | `3 + (D2 mod 3)` | ____ |
| Idle session timeout (minutes) | `5 + (D1 mod 5)` | ____ |
| IP block threshold (failures / 10 min) | `8 + D2` | ____ |
| Security question A | `Q[(D2 mod 8) + 1]` | ____ |
| Security question B | `Q[((D2 + 3) mod 8) + 1]` | ____ |
| Security question C | `Q[((D2 + 5) mod 8) + 1]` | ____ |

Question bank from Section 5:

| # | Question |
| --- | --- |
| Q1 | What was the name of your first pet? |
| Q2 | Who was your best friend in elementary school? |
| Q3 | Who was your favorite teacher in high school? |
| Q4 | What is your mother's maiden first name? |
| Q5 | What street did you live on in Grade 1? |
| Q6 | What was the first song you learned by heart? |
| Q7 | What was the name of your elementary school? |
| Q8 | What dish did you cook for the first time? |

**Note:** The handout requires your seed table on the *first page of the final documentation*. Complete the calculations from your own digits, confirm with instructor, and never substitute the example values from the handout.
