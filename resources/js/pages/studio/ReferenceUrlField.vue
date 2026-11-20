<script setup lang="ts">
import { X } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

defineProps<{
    index: number;
    value: string;
    disabled: boolean;
    canRemove: boolean;
    error?: string;
}>();

const emit = defineEmits<{
    update: [value: string];
    remove: [];
}>();

function update(value: string | number): void {
    emit('update', String(value));
}
</script>

<template>
    <div class="space-y-1">
        <div class="flex items-center gap-2">
            <Input
                :id="`reference-url-${index}`"
                :model-value="value"
                type="url"
                maxlength="2048"
                :aria-label="`Reference image URL ${index + 1}`"
                placeholder="https://example.com/reference.png"
                :disabled="disabled"
                @update:model-value="update"
            />
            <Button
                v-if="canRemove"
                type="button"
                variant="ghost"
                size="icon"
                :aria-label="`Remove reference image URL ${index + 1}`"
                :disabled="disabled"
                @click="$emit('remove')"
            >
                <X class="size-4" />
            </Button>
        </div>
        <p v-if="error" class="text-sm text-destructive">
            {{ error }}
        </p>
    </div>
</template>
