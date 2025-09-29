# 🎯 PHASE 4 - ADMIN WEB INTERFACE

## 📋 Overview

Phase 4 implementa l'interfaccia web amministrativa per il sistema Hospitality Manager, utilizzando **HTML + Vanilla JavaScript** (no frameworks) con **Tailwind CSS** per lo styling.

## 🏗️ Architecture

### Frontend Stack
- **HTML5** - Semantic markup
- **Vanilla JavaScript (ES6+)** - No frameworks, modular approach
- **Tailwind CSS (CDN)** - Rapid styling
- **Lucide Icons** - Modern icon set
- **Session Storage** - Token management (NO localStorage)

### Design Patterns
- **Module Pattern** - Ogni file JS è un modulo indipendente
- **MVC-like** - Separation of concerns (API, Auth, Utils, Controllers)
- **Progressive Enhancement** - Base funzionalità + enhancements
- **Mobile First** - Responsive design

## 📁 File Structure

```
httpdocs/
├── login.html                 # Login page
├── dashboard.html             # Main dashboard
├── guests.html                # Guest management
├── import.html                # Excel import
├── events.html                # Events CRUD
├── rooms.html                 # Rooms CRUD
├── users.html                 # Users/Hostess management
├── stadiums.html              # Stadiums (super admin)
│
├── js/
│   ├── config.js              # Global configuration
│   ├── auth.js                # Authentication manager
│   ├── api.js                 # API client wrapper
│   ├── utils.js               # Utility functions
│   ├── dashboard.js           # Dashboard controller
│   ├── guests.js              # Guests page controller
│   ├── import.js              # Import page controller
│   ├── events.js              # Events page controller
│   ├── rooms.js               # Rooms page controller
│   ├── users.js               # Users page controller
│   └── stadiums.js            # Stadiums page controller
│
└── assets/
    └── icons/                 # PWA icons
```

## 🔑 Key Features

### Authentication System (auth.js)
- Login/Logout flow
- JWT token management
- Automatic token refresh
- Session persistence (sessionStorage)
- Role-based access control
- Permission checking

### API Client (api.js)
- Centralized API communication
- Automatic token injection
- Token refresh on 401
- Error handling
- RESTful endpoints wrapper

### Utility Functions (utils.js)
- Date/time formatting
- Badge generators (VIP levels, roles, status)
- Toast notifications
- Confirmation dialogs
- File validation
- HTML escaping
- Debouncing

### Configuration (config.js)
- API endpoints
- Session keys
- Constants (roles, VIP levels, etc.)
- Validation rules
- Feature flags

## 🎨 UI Components

### Common Elements

#### Sidebar Navigation
- User info with avatar
- Role-based menu items
- Active state highlighting
- Logout button

#### Header
- Page title
- Breadcrumbs
- Notifications bell
- Settings icon

#### Stats Cards
- Icon + title
- Main metric (large number)
- Subtitle description
- Color-coded by type

#### Tables
- Sortable columns
- Search/filter
- Pagination
- Action buttons
- Row selection

#### Forms
- Inline validation
- Error messages
- Success feedback
- File upload with drag & drop

#### Modals
- Create/Edit dialogs
- Confirmation dialogs
- Loading overlays

### Color Palette

```css
Primary: #667eea (Purple)
Secondary: #764ba2 (Dark Purple)
Success: #10b981 (Green)
Warning: #f59e0b (Yellow)
Error: #ef4444 (Red)
Info: #3b82f6 (Blue)
Gray: #6b7280 (Neutral)
```

### Typography

```css
Font Family: 'Inter', sans-serif
Sizes: text-xs, text-sm, text-base, text-lg, text-xl, text-2xl
Weights: 300 (light), 400 (normal), 500 (medium), 600 (semibold), 700 (bold)
```

## 🔒 Security

### Token Management
- Tokens stored in **sessionStorage** (not localStorage)
- Tokens cleared on logout
- Tokens cleared on browser close
- No persistent sessions

### API Security
- All API calls use Authorization header
- Token automatically refreshed on expiry
- Failed refresh forces re-login
- CORS configured properly

### XSS Prevention
- All user input escaped with `Utils.escapeHtml()`
- No `innerHTML` with user data
- Safe DOM manipulation

### CSRF Protection
- Token-based auth (not cookies)
- No CSRF vulnerability

## 🚀 Deployment Process

### Step 1: Prepare Files Locally
```bash
# Create local structure
mkdir -p hospitality-frontend/{js,assets/icons}

# Copy files to local folder
cp login.html dashboard.html hospitality-frontend/
cp js/*.js hospitality-frontend/js/
```

### Step 2: Deploy to Server
```bash
# Upload files via SCP
scp -r hospitality-frontend/* checkindigitale@checkindigitale.cloud:/var/www/vhosts/checkindigitale.cloud/httpdocs/

# Or use SFTP client (FileZilla, Cyberduck, etc.)
```

### Step 3: Set Permissions
```bash
ssh checkindigitale@checkindigitale.cloud
cd /var/www/vhosts/checkindigitale.cloud/httpdocs
chmod 644 *.html
chmod 644 js/*.js
chmod 755 js/
```

### Step 4: Test
1. Open `https://checkindigitale.cloud/login.html`
2. Test login with credentials
3. Verify dashboard loads
4. Check browser console for errors
5. Test logout

## 🧪 Testing Strategy

### Manual Testing Checklist

#### Login Flow
- [ ] Login page loads
- [ ] Valid credentials work
- [ ] Invalid credentials show error
- [ ] Token saved in sessionStorage
- [ ] Redirect to dashboard after login

#### Dashboard
- [ ] User info displays correctly
- [ ] Stats cards show data
- [ ] Menu items visible based on role
- [ ] Quick actions work
- [ ] Upcoming events list
- [ ] Logout works

#### Navigation
- [ ] All menu links work
- [ ] Active state highlights current page
- [ ] Back button works
- [ ] Breadcrumbs accurate

#### Responsive Design
- [ ] Mobile (< 640px) works
- [ ] Tablet (640-1024px) works
- [ ] Desktop (> 1024px) works
- [ ] Touch interactions work

### Browser Compatibility
- ✅ Chrome 90+
- ✅ Firefox 88+
- ✅ Safari 14+
- ✅ Edge 90+
- ⚠️ IE11 NOT SUPPORTED

## 📊 Performance Targets

| Metric | Target | Status |
|--------|--------|--------|
| Login API | < 500ms | ✅ |
| Dashboard Load | < 1s | ✅ |
| Page Transitions | < 200ms | ✅ |
| API Calls | < 200ms avg | ✅ |
| Bundle Size | < 50KB (JS) | ✅ |

## 🐛 Common Issues & Solutions

### Issue: Login doesn't work
**Solution:**
1. Check API endpoint in config.js
2. Verify CORS headers in .htaccess
3. Check browser console for errors
4. Test API with curl

### Issue: Token not sent in requests
**Solution:**
1. Check sessionStorage has token
2. Verify Auth.getAuthHeader() returns correct format
3. Clear sessionStorage and login again

### Issue: Dashboard shows loading forever
**Solution:**
1. Check API endpoints respond correctly
2. Verify token is valid
3. Check stadium_id for non-super-admin users
4. Review browser console errors

### Issue: Styles not loading
**Solution:**
1. Check Tailwind CDN loads
2. Verify internet connection
3. Check browser console for CSP errors
4. Clear browser cache

## 🔄 Development Workflow

### Local Development
```bash
# Use local dev server
python3 -m http.server 8000

# Or use PHP built-in server
php -S localhost:8000

# Open browser
open http://localhost:8000/login.html
```

### Production Deployment
```bash
# Build (nothing to build, just copy files)
cp src/* dist/

# Deploy
rsync -avz dist/ user@server:/path/to/httpdocs/

# Test
curl https://checkindigitale.cloud/login.html
```

## 📝 Code Style Guide

### JavaScript
```javascript
// Use const for immutable, let for mutable
const API_URL = 'https://api.example.com';
let counter = 0;

// Use arrow functions
const fetchData = async () => {
    // ...
};

// Use template literals
const message = `Hello, ${userName}!`;

// Use destructuring
const { id, name } = user;

// Use async/await (not .then())
const data = await API.get('/endpoint');

// Add comments for complex logic
// Calculate total price with tax
const totalPrice = basePrice * (1 + taxRate);
```

### HTML
```html
<!-- Use semantic HTML5 tags -->
<header></header>
<main></main>
<footer></footer>
<nav></nav>
<article></article>

<!-- Use data attributes for JavaScript hooks -->
<button data-action="delete" data-id="123">Delete</button>

<!-- Use ARIA labels for accessibility -->
<button aria-label="Close modal">×</button>
```

### CSS (Tailwind)
```html
<!-- Use Tailwind utility classes -->
<div class="flex items-center justify-between p-4 bg-white rounded-lg shadow">

<!-- Group related utilities -->
<div class="w-full md:w-1/2 lg:w-1/3">

<!-- Use hover, focus, active states -->
<button class="hover:bg-blue-600 focus:outline-none focus:ring-2">
```

## 📚 Resources

### Documentation
- [Tailwind CSS Docs](https://tailwindcss.com/docs)
- [Lucide Icons](https://lucide.dev/)
- [MDN Web Docs](https://developer.mozilla.org/)

### Tools
- Browser DevTools (F12)
- Postman (API testing)
- VS Code (development)

## 🎯 Sprint Plan

### ✅ Sprint 1: Core Infrastructure (COMPLETED)
- Login page
- Dashboard
- Auth system
- API client
- Utils

### 🔄 Sprint 2: Guest Management (CURRENT)
- Guest list page
- Guest search
- Guest edit
- Excel import

### ⏳ Sprint 3: Admin Features
- Events CRUD
- Rooms CRUD
- Users management
- Room assignments

### ⏳ Sprint 4: Analytics
- Dashboard charts
- Room statistics
- Event reports
- Export functionality

## 🤝 Contributing

### Adding a New Page

1. Create HTML file: `newpage.html`
2. Create JS controller: `js/newpage.js`
3. Add menu item in all pages
4. Add route protection if needed
5. Test all user roles
6. Deploy

### Adding a New Feature

1. Update config.js if needed
2. Add API endpoint in api.js
3. Implement UI in HTML
4. Add controller logic in JS
5. Test thoroughly
6. Deploy

---

**Version:** 1.3.0  
**Status:** Sprint 1 Complete, Sprint 2 In Progress  
**Last Updated:** 2025-09-29  
**Maintainer:** Antonio Tartaglia - bitAND solution