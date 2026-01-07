# Brook's Dog Training Academy

Modern, responsive website redesign for Brook's Dog Training Academy using Bootstrap 5, HTML5, CSS3, and JavaScript with content extracted from the original Site.pro website.

## About

Brook's Dog Training Academy was founded in 2018 by Brook Lefkowitz, an Animal Behavior College Certified Dog Trainer. Based in Highlands County, Florida (serving Sebring, Avon Park, and Lake Placid), BDTA provides private dog training and group events.

**Tagline**: "Teaching Humans to Speak Dog"

**Status**: Certified & Insured

## Features

- **Modern Bootstrap 5 Design**: Fully responsive and mobile-first
- **Smooth Animations**: Using AOS (Animate On Scroll) library
- **Interactive Navigation**: Smooth scrolling and active link highlighting
- **Contact Form**: Built-in form validation and submission handling
- **Service Showcase**: Detailed information about all training services
- **Events Section**: Group workshops and community events
- **Social Media Integration**: Links to Facebook, Instagram, and Linktree
- **SEO Optimized**: Proper meta tags and semantic HTML

## Services Offered

- Pet Manners at Home I & II
- Walking Etiquette
- Social Manners
- Pawtner Support (for anxious dogs)
- Introducing Equipment
- Pet Sitting Services
- Group Workshops & Events

## Technology Stack

- HTML5
- CSS3 (Custom + Bootstrap 5.3.2)
- JavaScript (ES6+)
- Bootstrap Icons
- Google Fonts (Poppins & Montserrat)
- AOS Animation Library

## File Structure

```
├── index.html          # Main HTML file
├── css/
│   └── style.css      # Custom CSS styles
├── js/
│   └── script.js      # Custom JavaScript
├── assets/
│   ├── favicon.svg    # Website favicon
│   └── images/        # Image assets
│       ├── hero-dog.svg
│       ├── about-trainer.svg
│       └── .gitkeep
└── README.md
```

## Image Sources

The current design uses actual photos from the original website backup:

- `assets/images/hero-dog-real.jpg` → Dog training image from original site
- `assets/images/about-brook.jpg` → Photo of Brook Lefkowitz with her dog

Additional images available in the backup can be integrated for:
- Gallery section
- Event posters
- Blog post images
- Service illustrations

The original backup (50MB) contains 100+ professional photos that can be used throughout the site.

## Local Development

1. Clone this repository
2. Open `index.html` in a web browser
3. No build process required - all dependencies are loaded via CDN

## Customization

### Colors
Primary colors can be customized in `css/style.css`:
```css
:root {
    --primary-color: #2563eb;
    --primary-dark: #1e40af;
    --secondary-color: #10b981;
}
```

### Contact Form
The contact form currently logs to console. To implement actual email functionality:
1. Add a backend endpoint
2. Update the form submission in `js/script.js`
3. Consider using services like Formspree, EmailJS, or a custom PHP/Node.js backend

## Browser Support

- Chrome (latest)
- Firefox (latest)
- Safari (latest)
- Edge (latest)
- Mobile browsers (iOS Safari, Chrome Mobile)

## Credits

- Design & Development: Modern Bootstrap 5 responsive design
- Training Services: Brook's Dog Training Academy
- Icons: Bootstrap Icons
- Fonts: Google Fonts (Poppins & Montserrat)

## Contact

For more information about Brook's Dog Training Academy:
- Website: https://brooksdogtrainingacademy.com
- Facebook: https://www.facebook.com/BrooksDogTrainingAcademy
- Instagram: https://www.instagram.com/brooksdogtrainingacademy
- Linktree: https://linktr.ee/BrooksDogTraining

## License

© 2024 Brook's Dog Training Academy. All rights reserved.

