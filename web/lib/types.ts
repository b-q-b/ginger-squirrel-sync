/** TypeScript mirror of the .NET DTOs. Keep in lockstep with api/src/GingerSync.Core/Entities. */

export type Uuid = string;

export type SyncDirection = "TrelloToClickUp" | "ClickUpToTrello";

export interface Mapping {
  id: Uuid;
  label: string;
  trelloBoardId: string;
  trelloListId: string | null;
  clickUpSpaceId: string;
  clickUpListId: string;
  statusMap: Record<string, string>;
  isActive: boolean;
  createdAt: string;
}

export interface SyncEvent {
  id: number;
  createdAt: string;
  source: string;
  direction: SyncDirection | null;
  action: string;
  trelloCardId: string | null;
  clickUpTaskId: string | null;
  mappingId: Uuid | null;
  status: "ok" | "error" | "skipped" | string;
  error: string | null;
  payloadHash: string | null;
}

export interface SyncStats {
  totalMappings: number;
  activeMappings: number;
  totalEvents: number;
  events24h: number;
  errors24h: number;
  lastCronAt: string | null;
}

export type HotPlateColumn = "Todo" | "InProgress" | "Waiting" | "Done";
export type Priority = 1 | 2 | 3 | 4;
export type EnergyLevel = "Quick" | "Social" | "Deep" | "Creative";

export interface HotPlateItem {
  id: Uuid;
  title: string;
  description: string | null;
  column: HotPlateColumn;
  priority: Priority;
  dueDate: string | null;
  position: number;
  categoryId: Uuid | null;
  energyLevel: EnergyLevel | null;
  createdAt: string;
  updatedAt: string;
}

export interface HotPlateCategory {
  id: Uuid;
  name: string;
  color: string;
  sortOrder: number;
}

export type MeetingStatus = "Uploaded" | "Transcribing" | "Analyzing" | "Ready" | "Error" | "AudioOnly";

export interface Meeting {
  id: Uuid;
  title: string;
  recordedAt: string;
  durationMs: number | null;
  language: string;
  status: MeetingStatus;
  errorMessage: string | null;
  speakersExpected: number | null;
  transcript: string | null;
  analysis: MeetingAnalysis | null;
  hotPlateItemId: Uuid | null;
}

export interface MeetingAnalysis {
  summary: string[];
  decisions: { text: string }[];
  actionItems: { title: string; owner: string | null; due: string | null; context: string | null }[];
  questions: { question: string; context: string | null }[];
}

export interface IntegrationResult {
  ok: boolean;
  user?: { id?: number | string; username?: string; email?: string; fullName?: string };
  boards?: number;
  error?: string;
}
