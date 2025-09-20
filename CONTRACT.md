# Oval15 Plugin Contract (source of truth)

## Data model (user_meta keys we READ/WRITE)
- first_name, last_name
- p_profile
- v_link, v_upload_id
- gender, nation, current_location_country
- height, weight, years, months, level, league
- period_1/2/3, club_1/2/3, tournament_1/2/3
- main-position, secondary-position, interested_country, passport
- _oval15_approved, _oval15_sole_rep, _oval15_marketing_consent

## Shortcodes
- [oval15_complete_registration]

## Custom actions we EMIT (payload shape stable)
- oval15/registration_completed ($user_id, $order_id)
- oval15/user_approved ($user_id, $admin_id)
- oval15/user_declined ($user_id, $admin_id)
- oval15/profile_updated ($user_id, $changes)

## Webhook topics (Webhooks::topics() must include AT LEAST these)
- registration.completed
- user.approved
- user.declined
- order.completed
- email.sent
- profile.updated
