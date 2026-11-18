<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
    History,
    ImagePlus,
    Menu,
    Settings,
    Shield,
    Wallet,
    X,
} from '@lucide/vue';

const page = usePage();
const mobileMenuOpen = ref(false);

const currentUrl = computed(() => page.url.split('?')[0]);

const user = computed(() => {
    return page.props.auth?.user as
        | { name: string; email: string; is_admin?: boolean }
        | undefined;
});

const navigation = computed(() => [
    {
        label: 'Generate',
        href: '/studio',
        icon: ImagePlus,
    },
    {
        label: 'History',
        href: '/history',
        icon: History,
    },
    {
        label: 'Credit Wallet',
        href: '/wallet',
        icon: Wallet,
    },
    {
        label: 'Settings',
        href: '/settings/profile',
        icon: Settings,
    },
    ...(user.value?.is_admin
        ? [
              {
                  label: 'Admin Analytics',
                  href: '/admin/analytics',
                  icon: Shield,
              },
          ]
        : []),
]);

function isActive(href: string): boolean {
    return currentUrl.value === href || currentUrl.value.startsWith(`${href}/`);
}
</script>

<template>
    <div class="min-h-screen bg-[#0B0D12] text-[#F9FAFB]">
        <div class="flex min-h-screen flex-col md:flex-row">
            <aside
                class="border-b border-[#2A303B] bg-[#11141B] md:w-60 md:shrink-0 md:border-r md:border-b-0"
            >
                <div
                    class="flex h-16 items-center justify-between border-b border-[#2A303B] px-5"
                >
                    <Link href="/studio" class="flex items-center gap-3">
                        <div
                            class="flex size-9 items-center justify-center rounded-xl bg-[#3B82F6] font-bold text-white"
                        >
                            P
                        </div>

                        <div>
                            <div class="text-sm font-semibold">
                                Pixel Studio
                            </div>
                            <div
                                class="text-[10px] tracking-wider text-[#9CA3AF] uppercase"
                            >
                                Workspace
                            </div>
                        </div>
                    </Link>

                    <button
                        type="button"
                        class="rounded-lg p-2 md:hidden"
                        :aria-expanded="mobileMenuOpen"
                        aria-label="Toggle navigation"
                        @click="mobileMenuOpen = !mobileMenuOpen"
                    >
                        <X v-if="mobileMenuOpen" :size="20" />
                        <Menu v-else :size="20" />
                    </button>
                </div>

                <nav
                    :class="[
                        'space-y-1 p-3',
                        mobileMenuOpen ? 'block' : 'hidden md:block',
                    ]"
                    aria-label="Main navigation"
                >
                    <Link
                        v-for="item in navigation"
                        :key="item.href"
                        :href="item.href"
                        :aria-current="isActive(item.href) ? 'page' : undefined"
                        class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors"
                        :class="
                            isActive(item.href)
                                ? 'border border-[#3B82F6]/20 bg-[#3B82F6]/10 text-[#3B82F6]'
                                : 'text-[#9CA3AF] hover:bg-[#171B24] hover:text-white'
                        "
                        @click="mobileMenuOpen = false"
                    >
                        <component :is="item.icon" :size="18" />
                        <span>{{ item.label }}</span>
                    </Link>
                </nav>

                <div
                    v-if="user"
                    class="hidden border-t border-[#2A303B] p-4 md:block"
                >
                    <p class="truncate text-sm font-medium">
                        {{ user.name }}
                    </p>
                    <p class="truncate text-xs text-[#9CA3AF]">
                        {{ user.email }}
                    </p>
                </div>
            </aside>

            <main class="min-w-0 flex-1">
                <header
                    class="flex h-16 items-center border-b border-[#2A303B] bg-[#11141B]/70 px-6"
                >
                    <span class="text-sm font-medium"> Pixel Studio </span>
                </header>

                <div class="mx-auto w-full max-w-[1440px] p-6 lg:p-8">
                    <slot />
                </div>
            </main>
        </div>
    </div>
</template>
