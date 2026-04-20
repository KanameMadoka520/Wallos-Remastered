(function () {
  let currentActions = null;

  function hasActiveFilters(activeFilters) {
    return activeFilters['categories'].length > 0
      || activeFilters['members'].length > 0
      || activeFilters['payments'].length > 0
      || activeFilters['state'] !== ""
      || activeFilters['renewalType'] !== "";
  }

  function searchSubscriptions(activeFilters, updateSubscriptionReorderState, scheduleSubscriptionMasonryLayout) {
    const searchInput = document.querySelector("#search");
    const searchContainer = searchInput.parentElement;
    const searchTerm = searchInput.value.trim().toLowerCase();

    if (searchTerm.length > 0) {
      searchContainer.classList.add("has-text");
    } else {
      searchContainer.classList.remove("has-text");
    }

    const subscriptions = document.querySelectorAll(".subscription");
    subscriptions.forEach(subscription => {
      const name = subscription.getAttribute('data-name').toLowerCase();
      if (!name.includes(searchTerm)) {
        subscription.parentElement.classList.add("hide");
      } else {
        subscription.parentElement.classList.remove("hide");
      }
    });

    updateSubscriptionReorderState?.();
    scheduleSubscriptionMasonryLayout?.();
  }

  function clearSearch(activeFilters, updateSubscriptionReorderState, scheduleSubscriptionMasonryLayout) {
    const searchInput = document.querySelector("#search");
    searchInput.value = "";
    searchSubscriptions(activeFilters, updateSubscriptionReorderState, scheduleSubscriptionMasonryLayout);
  }

  function closeSubMenus() {
    const subMenus = document.querySelectorAll('.filtermenu-submenu-content');
    subMenus.forEach(subMenu => {
      subMenu.classList.remove('is-open');
    });
  }

  function toggleSubMenu(subMenu) {
    const target = document.getElementById("filter-" + subMenu);
    if (!target) {
      return;
    }

    if (target.classList.contains("is-open")) {
      closeSubMenus();
    } else {
      closeSubMenus();
      target.classList.add("is-open");
    }
  }

  function clearFilters(activeFilters, fetchSubscriptions) {
    const searchInput = document.querySelector("#search");
    searchInput.value = "";
    activeFilters['categories'] = [];
    activeFilters['members'] = [];
    activeFilters['payments'] = [];
    activeFilters['state'] = "";
    activeFilters['renewalType'] = "";

    document.querySelectorAll('.filter-item').forEach(function (item) {
      item.classList.remove('selected');
    });
    document.querySelector('#clear-filters').classList.add('hide');
    fetchSubscriptions(null, null, "clearfilters");
  }

  function expandActions(event, subscriptionId) {
    event.stopPropagation();
    event.preventDefault();
    const subscriptionDiv = document.querySelector(`.subscription[data-id="${subscriptionId}"]`);
    const actions = subscriptionDiv?.querySelector('.actions');
    const subscriptionContainer = subscriptionDiv?.closest('.subscription-container');

    if (!actions) {
      return;
    }

    const allActions = document.querySelectorAll('.actions.is-open');
    allActions.forEach((openAction) => {
      if (openAction !== actions) {
        openAction.classList.remove('is-open');
        const openContainer = openAction.closest('.subscription-container');
        if (openContainer) {
          openContainer.classList.remove('actions-menu-open');
        }
      }
    });

    const shouldOpen = !actions.classList.contains('is-open');
    actions.classList.toggle('is-open');
    if (subscriptionContainer) {
      subscriptionContainer.classList.toggle('actions-menu-open', shouldOpen);
    }

    currentActions = shouldOpen ? actions : null;
  }

  function setSwipeElements() {
    if (!window.mobileNavigation) {
      return;
    }

    const swipeElements = document.querySelectorAll('.subscription');

    swipeElements.forEach((element) => {
      if (element.dataset.swipeBound === "1") {
        return;
      }
      element.dataset.swipeBound = "1";

      let startX = 0;
      let startY = 0;
      let currentX = 0;
      let currentY = 0;
      let translateX = 0;
      const maxTranslateX = element.classList.contains('manual') ? -240 : -180;

      element.addEventListener('touchstart', (e) => {
        startX = e.touches[0].clientX;
        startY = e.touches[0].clientY;
        element.style.transition = '';
      });

      element.addEventListener('touchmove', (e) => {
        currentX = e.touches[0].clientX;
        currentY = e.touches[0].clientY;

        const diffX = currentX - startX;
        const diffY = currentY - startY;

        if (Math.abs(diffX) > Math.abs(diffY)) {
          e.preventDefault();

          if (!(translateX === maxTranslateX && diffX < 0)) {
            translateX = Math.min(0, Math.max(maxTranslateX, diffX));
            element.style.transform = `translateX(${translateX}px)`;
          }
        }
      });

      element.addEventListener('touchend', () => {
        if (translateX < maxTranslateX / 2) {
          translateX = maxTranslateX;
        } else {
          translateX = 0;
        }
        element.style.transition = 'transform 0.2s ease';
        element.style.transform = `translateX(${translateX}px)`;
        element.style.zIndex = '1';
      });
    });
  }

  function swipeHintAnimation() {
    if (window.mobileNavigation && window.matchMedia('(max-width: 768px)').matches) {
      const maxAnimations = 3;
      const cookieName = 'swipeHintCount';

      let count = parseInt(getCookie(cookieName)) || 0;
      if (count < maxAnimations) {
        const firstElement = document.querySelector('.subscription');
        if (firstElement) {
          firstElement.style.transition = 'transform 0.3s ease';
          firstElement.style.transform = 'translateX(-80px)';

          setTimeout(() => {
            firstElement.style.transform = 'translateX(0px)';
            firstElement.style.zIndex = '1';
          }, 600);
        }

        count++;
        document.cookie = `${cookieName}=${count}; expires=Fri, 31 Dec 9999 23:59:59 GMT; path=/; SameSite=Lax`;
      }
    }
  }

  function initialize(activeFilters, fetchSubscriptions, updateSubscriptionReorderState, scheduleSubscriptionMasonryLayout) {
    const filtermenu = document.querySelector('#filtermenu-button');
    if (filtermenu) {
      filtermenu.addEventListener('click', function () {
        this.parentElement.querySelector('.filtermenu-content').classList.toggle('is-open');
        closeSubMenus();
      });

      document.addEventListener('click', function (e) {
        const filtermenuContent = document.querySelector('.filtermenu-content');
        if (filtermenuContent && filtermenuContent.classList.contains('is-open')) {
          const subMenus = document.querySelectorAll('.filtermenu-submenu');
          const clickedInsideSubmenu = Array.from(subMenus).some(subMenu => subMenu.contains(e.target) || subMenu === e.target);

          if (!filtermenu.contains(e.target) && !clickedInsideSubmenu) {
            closeSubMenus();
            filtermenuContent.classList.remove('is-open');
          }
        }
      });
    }

    document.querySelectorAll('.filter-item').forEach(function (item) {
      item.addEventListener('click', function () {
        const searchInput = document.querySelector("#search");
        searchInput.value = "";

        if (this.hasAttribute('data-categoryid')) {
          const categoryId = this.getAttribute('data-categoryid');
          if (activeFilters['categories'].includes(categoryId)) {
            const categoryIndex = activeFilters['categories'].indexOf(categoryId);
            activeFilters['categories'].splice(categoryIndex, 1);
            this.classList.remove('selected');
          } else {
            activeFilters['categories'].push(categoryId);
            this.classList.add('selected');
          }
        } else if (this.hasAttribute('data-memberid')) {
          const memberId = this.getAttribute('data-memberid');
          if (activeFilters['members'].includes(memberId)) {
            const memberIndex = activeFilters['members'].indexOf(memberId);
            activeFilters['members'].splice(memberIndex, 1);
            this.classList.remove('selected');
          } else {
            activeFilters['members'].push(memberId);
            this.classList.add('selected');
          }
        } else if (this.hasAttribute('data-paymentid')) {
          const paymentId = this.getAttribute('data-paymentid');
          if (activeFilters['payments'].includes(paymentId)) {
            const paymentIndex = activeFilters['payments'].indexOf(paymentId);
            activeFilters['payments'].splice(paymentIndex, 1);
            this.classList.remove('selected');
          } else {
            activeFilters['payments'].push(paymentId);
            this.classList.add('selected');
          }
        } else if (this.hasAttribute('data-state')) {
          const state = this.getAttribute('data-state');
          if (activeFilters['state'] === state) {
            activeFilters['state'] = "";
            this.classList.remove('selected');
          } else {
            activeFilters['state'] = state;
            Array.from(this.parentNode.children).forEach(sibling => sibling.classList.remove('selected'));
            this.classList.add('selected');
          }
        } else if (this.hasAttribute('data-renewaltype')) {
          const renewalType = this.getAttribute('data-renewaltype');
          if (activeFilters['renewalType'] === renewalType) {
            activeFilters['renewalType'] = "";
            this.classList.remove('selected');
          } else {
            activeFilters['renewalType'] = renewalType;
            Array.from(this.parentNode.children).forEach(sibling => sibling.classList.remove('selected'));
            this.classList.add('selected');
          }
        }

        if (hasActiveFilters(activeFilters)) {
          document.querySelector('#clear-filters').classList.remove('hide');
        } else {
          document.querySelector('#clear-filters').classList.add('hide');
        }

        fetchSubscriptions(null, null, "filter");
      });
    });

    document.addEventListener('click', function (event) {
      if (currentActions && !currentActions.contains(event.target)) {
        currentActions.classList.remove('is-open');
        const currentContainer = currentActions.closest('.subscription-container');
        if (currentContainer) {
          currentContainer.classList.remove('actions-menu-open');
        }
        currentActions = null;
      }
    });

    setSwipeElements();
  }

  window.WallosSubscriptionInteractions = {
    initialize,
    hasActiveFilters,
    searchSubscriptions,
    clearSearch,
    closeSubMenus,
    toggleSubMenu,
    clearFilters,
    expandActions,
    setSwipeElements,
    swipeHintAnimation,
  };
})();
