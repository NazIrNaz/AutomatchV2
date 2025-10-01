**AutomatchV2**

AI‑assisted car recommendation web app for Malaysian buyers. Built with PHP + MySQL on the web tier and a small Flask (Python) service for scoring/ranking. Tailwind CSS powers the UI. This repo contains the PHP/Tailwind app; the Python service runs separately.

Automatch was originally developed as a Final Year Project (FYP) with the goal of helping users find suitable cars based on their demographics and financial background. The initial release (Beta FYP 1) implemented a rule-based recommendation system to suggest cars.

While the core functionality works, the project remains unfinished in terms of its side-project goals. The ultimate vision is to integrate machine learning to provide more accurate and explainable recommendations for users.

**⚠️ Status**: actively changing. Expect breaking refactors as features land (profiles, wishlist, compare, API auto‑start, etc.).

**⛔** **Current Limitations**

Recommendation Engine: Rule-based (hardcoded) system; no machine learning yet

Explainability: Cannot provide reasoning behind why a car is recommended

🗺️ **Roadmap (Side Project Goals)**

Upgrade the recommendation engine with machine learning models for improved accuracy

Add explainable AI (XAI) features so users understand why specific cars are recommended

**✨ Features**

Account system: Register/Login (PHP + MySQL), roles.

Profiles & Demographics: multiple saved profiles per user (monthly income/expenses, loans, family size, preferred brands, car types, etc.).

Recommendations: sends a JSON payload to a Flask API (/recommend) then renders ranked cars with affordability constraints.

Wishlist & Bookmarks: save cars you like, with quick access from the navbar.

Search & Compare: Car listing with filter + side‑by‑side compare (Carvilla‑style UI).

Smart images: Up‑scales car image URLs from s4 → s8x2 when available.

Admin flow (planned): scrape details from a source URL into a preview modal before inserting into DB.

**🧱 Tech Stack**

Frontend/Server: PHP 8+, Tailwind CSS, vanilla JS.

Backend DB: MySQL 8 (Laragon/XAMPP friendly).

Recommender API: Python 3.10+ with Flask.

**🧩 Key Pages

views/index.php – Landing page, search, quick links.

views/recommendations.php – Profile picker, inputs, Get Recommendations button.

views/profile.php – Cards for profiles; modal edit/new; delete with confirmation.

views/compare.php – Compare up to N cars side‑by‑side.

views/bookmarks.php – Wishlist/Bookmarks list.

**🛡️ Security Notes**

Always hash passwords (e.g., password_hash() in PHP).

Use prepared statements (mysqli/PDO).

Escape HTML output (e.g., htmlspecialchars).

Rate‑limit API calls if you expose the Python service.

Put real secrets in environment variables (do not commit credentials).
