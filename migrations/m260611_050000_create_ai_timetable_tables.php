<?php

use yii\db\Migration;

/**
 * AI Timetable feature tables:
 *  - ai_invocations / ai_proposals  (shared AI audit layer; created only if missing)
 *  - timetable_generation_runs     (one row per AI generation, draft → published)
 *  - timetable_generation_slots    (the generated week, per section × day × period)
 *
 * Publishing copies academic slots into the existing `subject_timetable` table;
 * these tables hold the reviewable draft and the full audit trail.
 */
class m260611_050000_create_ai_timetable_tables extends Migration
{
    public function safeUp()
    {
        $tableOptions = null;
        if ($this->db->driverName === 'mysql') {
            $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';
        }

        if ($this->db->schema->getTableSchema('ai_invocations', true) === null) {
            $this->createTable('ai_invocations', [
                'id'               => $this->primaryKey(),
                'tool_name'        => $this->string(64)->notNull(),
                'model'            => $this->string(64)->null(),
                'user_id'          => $this->integer()->null(),
                'institute_id'     => $this->integer()->null(),
                'campus_id'        => $this->integer()->null(),
                'prompt_hash'      => $this->char(64)->null(),
                'request_payload'  => 'MEDIUMTEXT NULL',
                'response_payload' => 'MEDIUMTEXT NULL',
                'tokens_in'        => $this->integer()->null(),
                'tokens_out'       => $this->integer()->null(),
                'latency_ms'       => $this->integer()->null(),
                'status'           => $this->string(16)->notNull()->defaultValue('success'),
                'error_message'    => $this->text()->null(),
                'created_on'       => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            ], $tableOptions);
            $this->createIndex('idx_ai_invocations_tool', 'ai_invocations', ['tool_name']);
            $this->createIndex('idx_ai_invocations_campus', 'ai_invocations', ['campus_id']);
        }

        if ($this->db->schema->getTableSchema('ai_proposals', true) === null) {
            $this->createTable('ai_proposals', [
                'id'              => $this->primaryKey(),
                'invocation_id'   => $this->integer()->notNull(),
                'target_table'    => $this->string(64)->notNull(),
                'target_pk'       => $this->string(64)->null(),
                'proposed_change' => 'MEDIUMTEXT NULL',
                'reasoning'       => $this->text()->null(),
                'status'          => $this->string(16)->notNull()->defaultValue('pending'),
                'decided_by'      => $this->integer()->null(),
                'decided_on'      => $this->dateTime()->null(),
                'created_on'      => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            ], $tableOptions);
            $this->createIndex('idx_ai_proposals_invocation', 'ai_proposals', ['invocation_id']);
            $this->createIndex('idx_ai_proposals_status', 'ai_proposals', ['status']);
        }

        $this->createTable('timetable_generation_runs', [
            'id'               => $this->primaryKey(),
            'campus_id'        => $this->integer()->notNull(),
            'class_id'         => $this->integer()->notNull(),
            'academic_year_id' => $this->integer()->notNull(),
            'section_ids'      => $this->text()->null()->comment('JSON array of class_sections.id covered by this run'),
            'rules_text'       => $this->text()->null()->comment('plain-English rules typed by the coordinator'),
            'constraints_json' => 'MEDIUMTEXT NULL',
            'stats_json'       => $this->text()->null(),
            'narrative'        => $this->text()->null()->comment('LLM explanation of the generated week'),
            'ai_invocation_id' => $this->integer()->null(),
            'status'           => $this->string(16)->notNull()->defaultValue('draft')->comment('draft|published|discarded|failed'),
            'published_on'     => $this->dateTime()->null(),
            'published_by'     => $this->integer()->null(),
            'created_on'       => $this->dateTime()->null(),
            'updated_on'       => $this->dateTime()->null(),
            'create_user_id'   => $this->integer()->null(),
            'update_user_id'   => $this->integer()->null(),
        ], $tableOptions);
        $this->createIndex('idx_tt_runs_scope', 'timetable_generation_runs', ['campus_id', 'class_id', 'academic_year_id']);
        $this->createIndex('idx_tt_runs_status', 'timetable_generation_runs', ['status']);

        $this->createTable('timetable_generation_slots', [
            'id'                       => $this->primaryKey(),
            'run_id'                   => $this->integer()->notNull(),
            'section_id'               => $this->integer()->notNull(),
            'day_id'                   => $this->smallInteger()->notNull()->comment('1=Monday … 7=Sunday'),
            'period'                   => $this->smallInteger()->null()->comment('academic period no; NULL for structural rows'),
            'kind'                     => $this->string(12)->notNull()->defaultValue('academic')->comment('academic|assembly|break|lunch|activity'),
            'subject_id'               => $this->integer()->null(),
            'subject_group_subject_id' => $this->integer()->null(),
            'teacher_details_id'       => $this->integer()->null(),
            'label'                    => $this->string(64)->null()->comment('display label for structural rows'),
            'time_from'                => $this->string(8)->notNull(),
            'time_to'                  => $this->string(8)->notNull(),
        ], $tableOptions);
        $this->createIndex('idx_tt_slots_run', 'timetable_generation_slots', ['run_id']);
        $this->createIndex('idx_tt_slots_cell', 'timetable_generation_slots', ['run_id', 'section_id', 'day_id']);
    }

    public function safeDown()
    {
        $this->dropTable('timetable_generation_slots');
        $this->dropTable('timetable_generation_runs');
        // ai_invocations / ai_proposals are shared with other AI features —
        // intentionally NOT dropped here.
        return true;
    }
}
