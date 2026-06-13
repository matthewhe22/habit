# Family Habit Tracker

A kid-friendly habit tracking app with virtual pets. Built with React + PHP.

## Features

- 🐉 Virtual pets (Dragon, Unicorn, Kitten, Doggy, Fish, Turtle, Bunny, Hamster, Parrot)
- ✅ Daily habit tracking with points system
- ⭐ Pet evolution system (Egg → Adult)
- 👨‍👩‍👧‍👦 Multi-kid support
- 📊 Progress tracking and stats
- 🌙 Sleep/wake cycle for pets

## Tech Stack

- **Frontend:** React 18 + Tailwind CSS (CDN)
- **Backend:** PHP (api.php)
- **Database:** SQLite (data/habit.db)
- **Hosting:** Nginx on Synology NAS

## Local Development

1. Serve `index.html` with any static server
2. PHP backend: `php -S 0.0.0.0:9090`
3. Database auto-creates on first request

## Deployment

Push to `main` branch → GitHub Actions auto-deploys to NAS.
