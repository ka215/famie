# Famie

**Small moments. Shared as a family.**

Famie is a simple place to record everyday activities, see what your family has been doing, and encourage one another with a heart. From finishing homework to helping with dinner, ordinary moments deserve a little recognition.

**Visit Famie: [famie.co](https://famie.co)**

## A little encouragement, every day

- **Keep a family timeline.** Record an activity with a date, category, and optional note.
- **Celebrate each other.** Like another family member's activity, see the hearts it receives, and undo your own like whenever you want.
- **Find the moments you need.** Browse by week or month, or filter by date range, family member, and category.
- **Keep photos with your activities.** Attach a photo and revisit family moments in the gallery.
- **Turn progress into rewards.** Set recurring goals, track progress, and collect earned trophies.
- **Make it your family's space.** Family administrators manage members and categories. Each person edits their own activity records.
- **Feel at home on your phone.** Add Famie to your home screen as a PWA, choose a light or dark theme, and customize your display name and header suffix.

<p align="center">
  <img src="docs/assets/screenshots/timeline-v0.13.0.png" width="430" alt="Famie family timeline showing daily activities, colorful categories, and heart reactions on a mobile screen">
</p>

*Illustrative sample data from v0.13.0. The sample shows the Japanese interface.*

## Made for families

An administrator creates a family and adds its members. Activities and categories stay within that family, with administrator and regular-member roles. Each account currently belongs to one family.

Rewards and activity photos are available. Each account currently belongs to one family; membership in multiple families is a future feature. Famie does not process payments.

The production site moved to **famie.co** in v0.16.0. Existing users sign in again on the new site and add its PWA to their home screen. The old URL redirects to the new site; old API clients must use the new site. The interface and account emails support Japanese and English as of v0.17.0. Each person can save their language preference; existing family content keeps its original wording.

## Built with

Nuxt 4, Vue, TypeScript, and Tailwind CSS on the frontend; Laravel, Sanctum, and PostgreSQL on the backend.

For setup, commands, repository structure, and reproducing the sample screenshot, see the [development guide](docs/development/workspace-guide.md).

- [Documentation](docs/README.md)
- [Requirements and release status](docs/issues/summary.md)
- [v0.16.0 specification and migration record](docs/issues/v0.16.0.md)
- [Contributor and agent instructions](AGENTS.md)
