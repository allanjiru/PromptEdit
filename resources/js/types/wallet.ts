export type WalletSummary = {
    available_units: number;
    available_usd: string;
    reserved_units: number;
    reserved_usd: string;
};

export type DemoCreditPackage = {
    id: string;
    label: string;
    amount_units: number;
    amount_usd: string;
};

export type DemoCreditConfig = {
    enabled: boolean;
    packages: DemoCreditPackage[];
};
