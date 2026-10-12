const categories = {
    1: { title: 'Announcements', description: 'Barangay announcements and community updates' },
    2: { title: 'Financial Reports', description: 'SK financial reports and public financial records' },
    3: { title: 'Projects & Budget', description: 'Projects, budgets, and community development updates' },
    4: { title: 'Meeting Minutes', description: 'Published minutes and records of meetings' }
};

function createElement(tag, options = {}, children = []) {
    const node = document.createElement(tag);
    if (options.className) node.className = options.className;
    if (options.text != null) node.textContent = options.text;
    if (options.attrs) {
        Object.entries(options.attrs).forEach(([name, value]) => node.setAttribute(name, value));
    }
    [].concat(children).forEach(child => {
        if (child == null) return;
        node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
    });
    return node;
}

function renderPosts(items, categoryId) {
    const list = document.getElementById('category-list');
    const status = document.getElementById('category-status');
    const posts = document.querySelector('.category-posts');
    const categoryItems = items.filter(item => Number(item.category_id) === categoryId);

    posts.setAttribute('aria-busy', 'false');
    if (categoryItems.length === 0) {
        status.textContent = 'No items yet.';
        return;
    }

    status.remove();
    categoryItems.forEach(item => {
        const posted = String(item.timestamp_posted ?? '');
        const article = createElement('article', { className: 'category-post' }, [
            createElement('h2', { text: item.title }),
            createElement('time', {
                text: new Date(posted).toLocaleDateString(),
                attrs: { datetime: posted }
            }),
            createElement('p', { text: item.content })
        ]);

        const safeFilePath = (typeof item.file_path === 'string'
            && /^uploads\/[\w./-]+$/.test(item.file_path))
            ? item.file_path
            : null;

        if (safeFilePath) {
            const label = createElement('span', { className: 'attachment-label' }, [
                createElement('i', { className: 'fas fa-paperclip', attrs: { 'aria-hidden': 'true' } }),
                ' Attachment'
            ]);
            const link = createElement('a', {
                className: 'attachment-link',
                text: item.file_name || 'Open attached file',
                attrs: { href: safeFilePath, target: '_blank', rel: 'noopener' }
            });
            article.appendChild(createElement('div', { className: 'attachment-section' }, [label, link]));
        }

        list.appendChild(article);
    });
}

async function loadCategory() {
    const categoryId = Number(new URLSearchParams(window.location.search).get('category'));
    const category = categories[categoryId];
    if (!category) {
        window.location.href = 'index.html#bulletin';
        return;
    }

    document.title = `${category.title} | Barangay San Jose Public Records`;
    document.getElementById('category-title').textContent = category.title;
    document.getElementById('category-description').textContent = category.description;

    try {
        const response = await fetch('api/disclosures.php');
        if (!response.ok) throw new Error('Unable to load bulletin posts');
        renderPosts(await response.json(), categoryId);
    } catch (error) {
        const posts = document.querySelector('.category-posts');
        posts.setAttribute('aria-busy', 'false');
        document.getElementById('category-status').textContent = 'Unable to load posts. Please try again later.';
    }
}

document.addEventListener('DOMContentLoaded', loadCategory);

document.addEventListener('DOMContentLoaded', () => {
    const footerYear = document.getElementById('bulletin-footer-year');
    if (footerYear) footerYear.textContent = String(new Date().getFullYear());

    // The standalone category page is also used by admins. Hide its public-site
    // disclaimer and footer for admin sessions, matching the admin bulletin view.
    fetch('api/session.php')
        .then(response => response.ok ? response.json() : null)
        .then(session => {
            if (session?.loggedIn && session.role === 'Admin') {
                document.body.classList.add('hide-site-disclaimer');
            }
        })
        .catch(() => {});
});
