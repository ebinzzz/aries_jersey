# Player Kit & Jersey Registration Interface

*Module Specification Document*

## Module Name
Player Kit Details Form

## Purpose
This module is used to collect all player kit requirements before the tournament season, including jersey sizes, helmet, pads, playing jersey preferences, and jersey number.

## 1. Player Information

| Field | Type | Required |
|---|---|---|
| Player Name | Text | Yes |

## 2. Kit Details

### 2.1 Jersey Size

**Upper Jersey**

| Field Type | Size |
|---|---|
| Dropdown | 36 – 44 (range) |

**Lower Jersey**

| Field Type | Size |
|---|---|
| Dropdown | 26 – 36 (range) |

### 2.2 Helmet Size

| Field Type | Options |
|---|---|
| Dropdown | Small, Medium, Large, XL |

### 2.3 Pad Size

| Field Type | Options |
|---|---|
| Dropdown | Youth, Small, Medium, Large |

### 2.4 Batting Hand

| Field Type | Options |
|---|---|
| Radio Button | Right Hand (RH), Left Hand (LH) |

## 3. Jersey Requirement

Display as checkboxes. (Developer can also implement this as quantity selectors instead of checkboxes.)

### 3.1 Playing Jersey

| Style | Field | Input Type | Selection |
|---|---|---|---|
| Half Sleeve | Quantity | Stepper (+ / -) | 0 - 3 |
| Full Sleeve | Quantity | Stepper (+ / -) | 0 - 3 |

**Rules:**
- Each field (Half Sleeve, Full Sleeve) individually allows a quantity of 0–3.
- The combined quantity of Half Sleeve and Full Sleeve jerseys must not exceed 4.
- Since each field caps at 3, a player cannot select 4 of the same style (e.g. 4 Full Sleeve or 4 Half Sleeve) — the maximum total of 4 must be made up of a mix, such as:
  - 2 Full Sleeve + 2 Half Sleeve
  - 1 Full Sleeve + 3 Half Sleeve
  - 3 Full Sleeve + 1 Half Sleeve
- Default value for both fields = 0.
- Minimum value = 0.

## 4. Jersey Number

| Priority | Input |
|---|---|
| Option 1 | Number |
| Option 2 | Number |
| Option 3 | Number |

**Validation**
- Jersey number should be between 0-99 (configurable).
- Duplicate numbers are not allowed.
- Option 1 is highest priority.

## 5. Jersey Name

| Field | Detail |
|---|---|
| Input Type | Single line textbox |
| Example | SUBIN |