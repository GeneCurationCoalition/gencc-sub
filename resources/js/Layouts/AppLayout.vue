<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import ApplicationMark from '@/Components/ApplicationMark.vue';
import Banner from '@/Components/Banner.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import AutoLogout from '@/Components/AutoLogout.vue';
import SubmitterSelector from '@/Components/SubmitterSelector.vue';

defineProps({
    title: String,
    headerClass: {
        type: String,
        default: 'bg-sky-800'
    }
});

const page = usePage();
const showingNavigationDropdown = ref(false);
const navigationContainer = ref(null);
const desktopContent = ref(null);
const useCompactNavigation = ref(true);
let navigationObserver;

onMounted(() => {
    // Keep the desktop row measurable while compact so it can expand again.
    const updateNavigation = () => {
        useCompactNavigation.value = desktopContent.value.getBoundingClientRect().width
            > navigationContainer.value.getBoundingClientRect().width;
    };

    navigationObserver = new ResizeObserver(updateNavigation);
    navigationObserver.observe(navigationContainer.value);
    navigationObserver.observe(desktopContent.value);
    updateNavigation();
});

onUnmounted(() => navigationObserver?.disconnect());

// Get the displayed submitter name
const displayedSubmitterName = computed(() => {
    // Show the user's submitter name from shared userSubmitter prop
    return page.props.userSubmitter?.name || '';
});

// Check if Jobs/Submissions should be visible (not for admins without a selected submitter)
const showJobsAndSubmissions = computed(() => {
    return !page.props.adminNeedsSubmitterSelection;
});

const switchToTeam = (team) => {
    router.put(route('current-team.update'), {
        team_id: team.id,
    }, {
        preserveState: false,
    });
};

const logout = () => {
    router.post(route('logout'));
};
</script>

<template>
    <div>
        <Head :title="title" />

        <Banner />

        <AutoLogout />

        <div class="min-h-screen bg-gray-100">
            <nav class="sticky top-0 z-50 bg-white border-b border-gray-100">
                <!-- Primary Navigation Menu -->
                <div class="px-4 sm:px-6 lg:px-8">
                    <div ref="navigationContainer" class="relative flex justify-between h-16" :class="{'overflow-hidden': useCompactNavigation}">
                        <div ref="desktopContent" class="flex justify-between w-max min-w-full shrink-0 h-16 pe-4" :class="{'absolute invisible pointer-events-none': useCompactNavigation}" :aria-hidden="useCompactNavigation ? true : null" :inert="useCompactNavigation ? '' : null">
                            <div class="flex">
                                <!-- Logo -->
                                <div class="shrink-0 flex items-center">
                                    <Link :href="route('dashboard')">
                                        <ApplicationMark class="block h-16 w-auto" />
                                    </Link>
                                </div>

                                <!-- Navigation Links -->
                                <div class="flex shrink-0 whitespace-nowrap ms-5">
                                    <NavLink :href="route('dashboard')" :active="route().current('dashboard')">
                                        Dashboard
                                    </NavLink>
                                </div>
                                <div v-if="showJobsAndSubmissions" class="flex shrink-0 whitespace-nowrap ms-5">
                                    <NavLink :href="route('jobs.index')" :active="route().current('jobs.index')">
                                        Jobs
                                    </NavLink>
                                </div>
                                <div v-if="showJobsAndSubmissions" class="flex shrink-0 whitespace-nowrap ms-5">
                                    <NavLink :href="route('submissions.index')" :active="route().current('submissions.index')">
                                        Submissions
                                    </NavLink>
                                </div>
                                <template v-if="$page.props.isGenccAdmin">
                                    <div class="flex shrink-0 whitespace-nowrap ms-5">
                                        <NavLink :href="route('admin.submitters')" :active="route().current('admin.submitters*')">
                                            Submitters
                                        </NavLink>
                                    </div>
                                    <div class="flex shrink-0 whitespace-nowrap ms-5">
                                        <NavLink :href="route('admin.users')" :active="route().current('admin.users*')">
                                            Users
                                        </NavLink>
                                    </div>
                                    <div class="flex shrink-0 whitespace-nowrap ms-5">
                                        <NavLink :href="route('admin.releases')" :active="route().current('admin.releases*')">
                                            Releases
                                        </NavLink>
                                    </div>
                                    <div class="flex shrink-0 whitespace-nowrap ms-5">
                                        <NavLink :href="route('admin.disease-ontology-audit')" :active="route().current('admin.disease-ontology-audit*')">
                                            Disease Audit
                                        </NavLink>
                                    </div>
                                </template>
                                <div class="flex shrink-0 whitespace-nowrap ms-5">
                                    <NavLink :href="route('help')" :active="route().current('help*')">
                                        Help  & Documentation
                                    </NavLink>
                                </div>
                            </div>

                            <div class="flex shrink-0 items-center ms-6">
                                <!-- Submitter Selector for GenCC Administrator -->
                                <SubmitterSelector />

                                <!-- Read-only Submitter Name Display -->
                                <div v-if="$page.props.jetstream.hasTeamFeatures && displayedSubmitterName" class="ms-3">
                                    <span class="inline-flex items-center px-3 py-2 text-sm leading-4 font-medium text-gray-500">
                                        {{ displayedSubmitterName }}
                                    </span>
                                </div>

                                <!-- Settings Dropdown -->
                                <div class="ms-3 relative">
                                    <Dropdown align="right" width="48">
                                        <template #trigger>
                                            <button v-if="$page.props.jetstream.managesProfilePhotos" class="flex text-sm border-2 border-transparent rounded-full focus:outline-none focus:border-gray-300 transition">
                                                <img class="h-8 w-8 rounded-full object-cover" :src="$page.props.auth.user.profile_photo_url" :alt="$page.props.auth.user.name">
                                            </button>

                                            <span v-else class="inline-flex rounded-md">
                                                <button type="button" class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 bg-white hover:text-gray-700 focus:outline-none focus:bg-gray-50 active:bg-gray-50 transition ease-in-out duration-150">
                                                    {{ $page.props.auth.user.name }}

                                                    <svg class="ms-2 -me-0.5 h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                                    </svg>
                                                </button>
                                            </span>
                                        </template>

                                        <template #content>
                                            <DropdownLink :href="route('profile.show')">
                                                <i class="pi pi-user mr-2"></i> Profile
                                            </DropdownLink>

                                            <DropdownLink v-if="$page.props.adminNeedsSubmitterSelection" :href="route('team.show')">
                                                <i class="pi pi-users mr-2"></i> Team
                                            </DropdownLink>
                                            <DropdownLink v-else :href="route('submitter.show')">
                                                <i class="pi pi-building mr-2"></i> Submitter
                                            </DropdownLink>

                                            <!--
                                            <DropdownLink v-if="$page.props.jetstream.hasApiFeatures" :href="route('api-tokens.index')">
                                                API Tokens
                                            </DropdownLink> -->

                                            <div class="border-t border-gray-200" />

                                            <!-- Authentication -->
                                            <form @submit.prevent="logout" id="logout-form">
                                                <DropdownLink as="button">
                                                    <i class="pi pi-sign-out mr-2"></i> Log Out
                                                </DropdownLink>
                                            </form>
                                        </template>
                                    </Dropdown>
                                </div>
                            </div>
                        </div>

                        <!-- Compact logo and hamburger -->
                        <div v-if="useCompactNavigation" class="flex items-center justify-between w-full">
                            <Link :href="route('dashboard')">
                                <ApplicationMark class="block h-16 w-auto" />
                            </Link>
                            <button aria-label="Toggle navigation" :aria-expanded="showingNavigationDropdown" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 focus:text-gray-500 transition duration-150 ease-in-out" @click="showingNavigationDropdown = ! showingNavigationDropdown">
                                <svg
                                    class="h-6 w-6"
                                    stroke="currentColor"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        :class="{'hidden': showingNavigationDropdown, 'inline-flex': ! showingNavigationDropdown }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M4 6h16M4 12h16M4 18h16"
                                    />
                                    <path
                                        :class="{'hidden': ! showingNavigationDropdown, 'inline-flex': showingNavigationDropdown }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Responsive Navigation Menu -->
                <div v-show="useCompactNavigation && showingNavigationDropdown">
                    <div v-if="$page.props.isGenccAdmin" class="flex justify-end px-4 py-2">
                        <SubmitterSelector />
                    </div>

                    <div class="pt-2 pb-3 space-y-1">
                        <ResponsiveNavLink :href="route('dashboard')" :active="route().current('dashboard')">
                            Dashboard
                        </ResponsiveNavLink>
                        <ResponsiveNavLink v-if="showJobsAndSubmissions" :href="route('jobs.index')" :active="route().current('jobs.index')">
                            Jobs
                        </ResponsiveNavLink>
                        <ResponsiveNavLink v-if="showJobsAndSubmissions" :href="route('submissions.index')" :active="route().current('submissions.index')">
                            Submissions
                        </ResponsiveNavLink>
                        <ResponsiveNavLink v-if="$page.props.isGenccAdmin" :href="route('admin.submitters')" :active="route().current('admin.submitters*')">
                            Submitters
                        </ResponsiveNavLink>
                        <ResponsiveNavLink v-if="$page.props.isGenccAdmin" :href="route('admin.users')" :active="route().current('admin.users*')">
                            Users
                        </ResponsiveNavLink>
                        <ResponsiveNavLink v-if="$page.props.isGenccAdmin" :href="route('admin.releases')" :active="route().current('admin.releases*')">
                            Releases
                        </ResponsiveNavLink>
                        <ResponsiveNavLink v-if="$page.props.isGenccAdmin" :href="route('admin.disease-ontology-audit')" :active="route().current('admin.disease-ontology-audit*')">
                            Disease Audit
                        </ResponsiveNavLink>
                        <ResponsiveNavLink :href="route('help')" :active="route().current('help')">
                            Help & Documentation
                        </ResponsiveNavLink>
                    </div>

                    <!-- Responsive Settings Options -->
                    <div class="pt-4 pb-1 border-t border-gray-200">
                        <div class="flex items-center px-4">
                            <div v-if="$page.props.jetstream.managesProfilePhotos" class="shrink-0 me-3">
                                <img class="h-10 w-10 rounded-full object-cover" :src="$page.props.auth.user.profile_photo_url" :alt="$page.props.auth.user.name">
                            </div>

                            <div>
                                <div class="font-medium text-base text-gray-800">
                                    {{ $page.props.auth.user.name }}
                                </div>
                                <div class="font-medium text-sm text-gray-500">
                                    {{ $page.props.auth.user.email }}
                                </div>
                            </div>
                        </div>

                        <div class="mt-3 space-y-1">
                            <ResponsiveNavLink :href="route('profile.show')" :active="route().current('profile.show')">
                                Profile
                            </ResponsiveNavLink>

                            <ResponsiveNavLink v-if="$page.props.adminNeedsSubmitterSelection" :href="route('team.show')" :active="route().current('team.show')">
                                Team
                            </ResponsiveNavLink>
                            <ResponsiveNavLink v-else :href="route('submitter.show')" :active="route().current('submitter.show')">
                                Submitter
                            </ResponsiveNavLink>

                            <ResponsiveNavLink v-if="$page.props.jetstream.hasApiFeatures" :href="route('api-tokens.index')" :active="route().current('api-tokens.index')">
                                API Tokens
                            </ResponsiveNavLink>

                            <!-- Authentication -->
                            <form method="POST" @submit.prevent="logout">
                                <ResponsiveNavLink as="button">
                                    Log Out
                                </ResponsiveNavLink>
                            </form>

                            <!-- Team Management -->
                            <template v-if="$page.props.jetstream.hasTeamFeatures">
                                <div class="border-t border-gray-200" />

                                <div class="block px-4 py-2 text-xs text-gray-400">
                                    Manage Team
                                </div>

                                <!-- Team Settings -->
                                <ResponsiveNavLink :href="route('teams.show', $page.props.auth.user.current_team)" :active="route().current('teams.show')">
                                    Team Settings
                                </ResponsiveNavLink>

                                <ResponsiveNavLink v-if="$page.props.jetstream.canCreateTeams" :href="route('teams.create')" :active="route().current('teams.create')">
                                    Create New Team
                                </ResponsiveNavLink>

                                <!-- Team Switcher -->
                                <template v-if="$page.props.auth.user.all_teams.length > 1">
                                    <div class="border-t border-gray-200" />

                                    <div class="block px-4 py-2 text-xs text-gray-400">
                                        Switch Teams
                                    </div>

                                    <template v-for="team in $page.props.auth.user.all_teams" :key="team.id">
                                        <form @submit.prevent="switchToTeam(team)">
                                            <ResponsiveNavLink as="button">
                                                <div class="flex items-center">
                                                    <svg v-if="team.id == $page.props.auth.user.current_team_id" class="me-2 h-5 w-5 text-green-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                    </svg>
                                                    <div>{{ team.name }}</div>
                                                </div>
                                            </ResponsiveNavLink>
                                        </form>
                                    </template>
                                </template>
                            </template>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Page Heading -->
            <header v-if="$slots.header" :class="[headerClass, 'shadow']">
                <div class="max-w-7xl mx-auto py-4 px-4 sm:px-6 lg:px-8">
                    <slot name="header" />
                </div>
            </header>

            <!-- Page Content -->
            <main>
                <slot />
            </main>
        </div>
    </div>
</template>
